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
        parse_str((string) ($parts['query'] ?? ''), $query);
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

        $query = array_filter($query, function ($value, $key) use ($strip, $keep) {
            $key = (string) $key;

            if (in_array($key, $keep, true)) {
                return true;
            }

            foreach ($strip as $pattern) {
                $pattern = (string) $pattern;
                if ($key === $pattern || (str_ends_with($pattern, '*') && str_starts_with($key, rtrim($pattern, '*')))) {
                    return false;
                }
            }

            return true;
        }, ARRAY_FILTER_USE_BOTH);

        return strtolower((string) ($parts['scheme'] ?? 'https')).'://'.$host
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .$path
            .($query === [] ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986))
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }

    /**
     * Cleans every URL in a stored links value (Grid rows or a List) and
     * says whether anything changed.
     *
     * @return array{0: mixed, 1: int} the cleaned value and the number of changed URLs
     */
    public function cleanRows(mixed $rows, string $urlKey): array
    {
        if (! is_array($rows)) {
            return [$rows, 0];
        }

        $changed = 0;

        foreach ($rows as $i => $row) {
            $url = is_array($row) ? ($row[$urlKey] ?? null) : $row;

            if (! is_string($url) || $url === '') {
                continue;
            }

            $clean = $this->clean($url);

            if ($clean !== $url) {
                $changed++;
                is_array($row) ? $rows[$i][$urlKey] = $clean : $rows[$i] = $clean;
            }
        }

        return [$rows, $changed];
    }
}
