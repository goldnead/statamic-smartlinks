<?php

namespace Goldnead\Smartlinks;

/**
 * Brings a streaming URL into one canonical form and removes what does not
 * belong to the link: somebody else's affiliate token (Odesli's `at=` sat on
 * every Apple link of the ANDERS site, the commission went to Odesli),
 * campaign tracking, share IDs.
 *
 * Normalisations: `spotify:track:` URIs and `intl-xx` segments, Apple's
 * `geo.` and `itunes.` hosts and the old `id` prefix, Deezer's language
 * prefix, Tidal's `listen.` host, `/browse/` and trailing `/u`. The track
 * parameter `i` on an Apple album URL is kept: it is what makes it a track.
 *
 * Parameters in `smartlinks.cleanup.keep` are never removed (your own
 * affiliate token, say); `smartlinks.cleanup.strip` adds more.
 */
class LinkCleaner
{
    /** Removed on every host. `utm_*` by prefix. */
    public const STRIP_ALWAYS = ['utm_*', 'fbclid', 'gclid', 'igshid', 'si'];

    /** @var array<string, list<string>> platform => parameters */
    public const STRIP_PER_PLATFORM = [
        'applemusic' => ['at', 'ct', 'uo', 'app', 'itsct', 'itscg', 'ls', 'mt', 'pt'],
        'spotify' => ['si', 'nd', 'context', 'dl_branch'],
        'deezer' => ['deferredFl', 'host', 'app_id'],
        'tidal' => ['play'],
    ];

    public function clean(string $url): string
    {
        $url = trim($url);

        if (preg_match('/^spotify:(track|album|artist|playlist):([A-Za-z0-9]{22})$/', $url, $m) === 1) {
            return "https://open.spotify.com/{$m[1]}/{$m[2]}";
        }

        if (! Platforms::isWebUrl($url)) {
            return $url;
        }

        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        $originalHost = $host;
        $originalPath = $path;
        $platform = app(Platforms::class)->detect($url);

        switch ($platform) {
            case 'applemusic':
                if (in_array($host, ['geo.music.apple.com', 'itunes.apple.com'], true)) {
                    $host = 'music.apple.com';
                }
                $path = (string) preg_replace('~/id(\d+)(?=/|$)~', '/$1', $path);
                break;
            case 'spotify':
                $path = (string) preg_replace('~^/intl-[a-z]{2}(?:-[a-z]{2})?(?=/)~i', '', $path);
                break;
            case 'deezer':
                $path = (string) preg_replace('~^/[a-z]{2}(?:-[a-z]{2})?(?=/(track|album|artist|playlist)/)~i', '', $path);
                break;
            case 'tidal':
                if ($host === 'listen.tidal.com' || $host === 'www.tidal.com') {
                    $host = 'tidal.com';
                }
                $path = (string) preg_replace('~^/browse(?=/)~', '', $path);
                $path = (string) preg_replace('~/u$~', '', $path);
                break;
        }

        $strip = [...self::STRIP_ALWAYS, ...(self::STRIP_PER_PLATFORM[$platform] ?? []), ...(array) config('smartlinks.cleanup.strip', [])];
        $keep = array_map('strval', (array) config('smartlinks.cleanup.keep', []));

        // The raw query, split by hand: parse_str() would rename keys
        // (`a.b` → `a_b`) and re-encoding would change URLs that had nothing
        // to remove. Only the key is decoded, for matching.
        $raw = (string) ($parts['query'] ?? '');
        $pieces = $raw === '' ? [] : explode('&', $raw);
        $kept = array_values(array_filter($pieces, function (string $piece) use ($strip, $keep) {
            $key = urldecode(explode('=', $piece, 2)[0]);

            if ($piece === '' || in_array($key, $keep, true)) {
                return $piece !== '';
            }

            foreach ($strip as $pattern) {
                $pattern = (string) $pattern;
                if ($key === $pattern || (str_ends_with($pattern, '*') && str_starts_with($key, rtrim($pattern, '*')))) {
                    return false;
                }
            }

            return true;
        }));

        if ($kept === $pieces && $host === $originalHost && $path === $originalPath) {
            return $url;
        }

        return strtolower((string) ($parts['scheme'] ?? 'https')).'://'.$host
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .$path
            .($kept === [] ? '' : '?'.implode('&', $kept))
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }

    /**
     * Cleans every URL in a stored links value (Grid rows or a List). A row
     * whose cleaned URL an earlier row already holds is dropped: Odesli
     * stored every Apple link twice, `app=itunes` and `app=music`.
     *
     * @return array{0: mixed, 1: int, 2: array<string, string>} the cleaned value, the number of
     *                                                           changed or dropped rows, old URL => new URL
     */
    public function cleanRows(mixed $rows, string $urlKey): array
    {
        if (! is_array($rows)) {
            return [$rows, 0, []];
        }

        $changed = 0;
        $renamed = [];
        $seen = [];
        $list = array_is_list($rows);

        foreach ($rows as $i => $row) {
            $url = is_array($row) ? ($row[$urlKey] ?? null) : $row;

            if (! is_string($url) || $url === '') {
                continue;
            }

            $clean = $this->clean($url);

            if (isset($seen[$clean])) {
                unset($rows[$i]);
                $changed++;
                $renamed[$url] = $clean;

                continue;
            }

            $seen[$clean] = true;

            if ($clean !== $url) {
                $changed++;
                $renamed[$url] = $clean;
                is_array($row) ? $rows[$i][$urlKey] = $clean : $rows[$i] = $clean;
            }
        }

        return [$list ? array_values($rows) : $rows, $changed, $renamed];
    }
}
