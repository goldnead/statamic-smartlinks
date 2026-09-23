<?php

namespace Goldnead\Smartlinks;

/**
 * Which platform a URL belongs to, decided by its host alone.
 *
 * Never by a hand-typed label: on the ANDERS site 216 of 444 hand-labelled
 * links pointed somewhere else than their label said. The table started as
 * the one in anders-band.de's Webflow import and is extended here.
 *
 * A host matches itself and every subdomain; the longest match wins, so
 * music.youtube.com is YouTube Music and geo.music.apple.com is Apple Music.
 */
class Platforms
{
    public const OTHER = 'other';

    /**
     * @var array<string, array{label: string, hosts: list<string>}>
     */
    public const BUILT_IN = [
        'spotify' => ['label' => 'Spotify', 'hosts' => ['spotify.com', 'spotify.link', 'tospotify.com']],
        'applemusic' => ['label' => 'Apple Music', 'hosts' => ['music.apple.com', 'itunes.apple.com']],
        'amazonmusic' => ['label' => 'Amazon Music', 'hosts' => [
            'music.amazon.com', 'music.amazon.de', 'music.amazon.co.uk', 'music.amazon.fr',
            'amazon.com', 'amazon.de', 'amazon.co.uk', 'amazon.fr', 'amazon.it', 'amazon.es', 'amazon.at',
        ]],
        'youtubemusic' => ['label' => 'YouTube Music', 'hosts' => ['music.youtube.com']],
        'youtube' => ['label' => 'YouTube', 'hosts' => ['youtube.com', 'youtu.be', 'youtube-nocookie.com']],
        'tidal' => ['label' => 'Tidal', 'hosts' => ['tidal.com', 'tidal.link']],
        'deezer' => ['label' => 'Deezer', 'hosts' => ['deezer.com', 'deezer.page.link', 'dzr.page.link']],
        'soundcloud' => ['label' => 'SoundCloud', 'hosts' => ['soundcloud.com', 'snd.sc']],
        'bandcamp' => ['label' => 'Bandcamp', 'hosts' => ['bandcamp.com']],
        'yandex' => ['label' => 'Yandex Music', 'hosts' => ['music.yandex.ru', 'music.yandex.com', 'yandex.ru', 'yandex.com']],
        'anghami' => ['label' => 'Anghami', 'hosts' => ['anghami.com']],
        'boomplay' => ['label' => 'Boomplay', 'hosts' => ['boomplay.com', 'boomplaymusic.com']],
        'pandora' => ['label' => 'Pandora', 'hosts' => ['pandora.com', 'pandora.app.link']],
        'napster' => ['label' => 'Napster', 'hosts' => ['napster.com', 'napster.de']],
        'audiomack' => ['label' => 'Audiomack', 'hosts' => ['audiomack.com']],
        'qobuz' => ['label' => 'Qobuz', 'hosts' => ['qobuz.com']],
        'beatport' => ['label' => 'Beatport', 'hosts' => ['beatport.com']],
        'kkbox' => ['label' => 'KKBOX', 'hosts' => ['kkbox.com']],
    ];

    /** @var array<string, string>|null host => platform, longest host first */
    protected ?array $hosts = null;

    /**
     * @param  array<string, list<string>>  $extra  platform => hosts, from config
     */
    public function __construct(protected array $extra = []) {}

    /**
     * The platform handle for a URL, `other` for any host not in the table
     * and for anything that is not an http(s) URL.
     */
    public function detect(?string $url): string
    {
        $host = self::host($url);

        if ($host === null) {
            return self::OTHER;
        }

        foreach ($this->hosts() as $known => $platform) {
            if ($host === $known || str_ends_with($host, '.'.$known)) {
                return $platform;
            }
        }

        return self::OTHER;
    }

    public function label(string $platform): string
    {
        if ($platform === self::OTHER) {
            return (string) __('smartlinks::messages.other');
        }

        return self::BUILT_IN[$platform]['label'] ?? ucfirst($platform);
    }

    /**
     * host => platform, longest first so the more specific host wins. Also
     * what the fieldtype hands the browser, so both sides decide alike.
     *
     * @return array<string, string>
     */
    public function hosts(): array
    {
        if ($this->hosts !== null) {
            return $this->hosts;
        }

        $map = [];
        foreach (self::BUILT_IN as $platform => $definition) {
            foreach ($definition['hosts'] as $host) {
                $map[$host] = $platform;
            }
        }
        foreach ($this->extra as $platform => $hosts) {
            foreach ((array) $hosts as $host) {
                $map[strtolower((string) $host)] = (string) $platform;
            }
        }

        uksort($map, fn (string $a, string $b) => strlen($b) <=> strlen($a) ?: strcmp($a, $b));

        return $this->hosts = $map;
    }

    /**
     * The lower-cased host of an http(s) URL without `www.`, or null.
     */
    public static function host(?string $url): ?string
    {
        if (! is_string($url) || ! self::isWebUrl($url)) {
            return null;
        }

        $host = strtolower((string) parse_url(trim($url), PHP_URL_HOST));

        return $host === '' ? null : preg_replace('/^www\./', '', rtrim($host, '.'));
    }

    /**
     * Only http and https URLs are links. A stored `javascript:` or `data:`
     * value is never listed and never redirected to.
     */
    public static function isWebUrl(string $url): bool
    {
        $url = trim($url);

        return preg_match('~^https?://[^\s/?#]+~i', $url) === 1
            && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
