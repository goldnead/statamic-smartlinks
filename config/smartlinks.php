<?php

use Goldnead\Smartlinks\Resolvers\DeezerResolver;
use Goldnead\Smartlinks\Resolvers\SpotifyResolver;
use Goldnead\Smartlinks\Resolvers\YouTubeResolver;

return [

    /*
    |--------------------------------------------------------------------------
    | Where the links live
    |--------------------------------------------------------------------------
    |
    | Songs and releases stay ordinary collections of the site. The addon only
    | reads them. `collections` are the collections whose entries get a smart
    | link page; `field` is the field holding the streaming links.
    |
    | `field` is typically a Grid (or Replicator) with one URL per row, the URL
    | under `url_key`. A plain List of URLs works too. Any other column in the
    | row (a hand-typed "platform", say) is ignored: the platform is always
    | derived from the URL's host, never from a label.
    |
    | `spotify_field` holds a Spotify track ID or URL, the starting point for
    | `smartlinks:resolve`. `isrc_field` is optional; with it, Deezer can be
    | filled without Spotify credentials.
    |
    */

    'collections' => ['songs'],

    'field' => 'streaming_links',

    'url_key' => 'url',

    'spotify_field' => 'spotify_id',

    'isrc_field' => null,

    /*
    | Where `smartlinks:resolve` writes the platform of a link it adds, next
    | to the URL, for templates that render a row's `platform`. The addon
    | itself never reads it back. `platform_value` is `handle` (spotify, as
    | anders-band.de's rows hold it) or `label` (Spotify). null: URL only.
    */

    'platform_key' => 'platform',

    'platform_value' => 'handle',

    /*
    |--------------------------------------------------------------------------
    | Extra hosts
    |--------------------------------------------------------------------------
    |
    | Added to the built-in host table, e.g. ['audiomack' => ['audiomack.com']].
    | A host matches itself and every subdomain. More specific hosts win, so
    | music.youtube.com is YouTube Music although youtube.com is YouTube.
    |
    */

    'platforms' => [],

    /*
    | The order of the buttons on the landing page and in {{ smartlinks:links }}.
    | Platforms not listed follow alphabetically, "other" last. Stored row
    | order does not matter.
    */

    'priority' => [
        'spotify', 'applemusic', 'youtubemusic', 'amazonmusic', 'deezer',
        'tidal', 'youtube', 'soundcloud', 'bandcamp', 'amazon',
    ],

    /*
    |--------------------------------------------------------------------------
    | Front-end routes
    |--------------------------------------------------------------------------
    |
    | GET {prefix}/{slug}              the landing page, one button per platform
    | GET {prefix}/{slug}/{platform}   302 to the stored URL, counts the click
    |
    | The redirect only ever goes to a URL stored on the entry; nothing from
    | the request becomes a location. `view` is any Blade or Antlers view; it
    | receives `entry`, `title` and `links`.
    |
    | Deliberately not throttled: a room full of people scanning one QR code
    | shares the venue's IP. Only the counting is capped, see below.
    |
    */

    'routes' => [
        'enabled' => (bool) env('SMARTLINKS_ROUTES_ENABLED', true),
        'prefix' => 'hoeren',
        'view' => 'smartlinks::landing',
    ],

    /*
    |--------------------------------------------------------------------------
    | Click counting
    |--------------------------------------------------------------------------
    |
    | One counter per song, platform and day in `smartlinks_clicks`. No IP, no
    | cookie, no user agent is stored. Requests whose user agent is empty or
    | contains one of `bots` (case-insensitive) are redirected but not counted,
    | and so are HEAD requests, which link previews send, and browser
    | prefetches (Sec-Purpose / Purpose / X-Moz: prefetch).
    |
    | `per_minute` caps counted clicks per IP, song and platform; beyond it
    | the listener is still redirected, uncounted. 0 = no cap. `prune_days`
    | is the default for `smartlinks:prune`.
    |
    */

    'clicks' => [
        'enabled' => true,
        'per_minute' => 10,
        'prune_days' => 400,
        'bots' => [
            'bot', 'crawl', 'spider', 'slurp', 'preview', 'facebookexternalhit',
            'whatsapp', 'telegram', 'curl', 'wget', 'python-requests', 'headless',
            'lighthouse', 'monitor',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto-fill
    |--------------------------------------------------------------------------
    |
    | Used by `php artisan smartlinks:resolve`, in this order. Only services
    | that are free: Spotify's Web API (client credentials), Deezer's public
    | ISRC lookup (no key), and optionally the YouTube Data API. Apple Music,
    | Amazon, Tidal and the rest stay hand-entered.
    |
    */

    'resolvers' => [
        SpotifyResolver::class,
        DeezerResolver::class,
        YouTubeResolver::class,
    ],

    'services' => [
        'spotify' => [
            'client_id' => env('SPOTIFY_CLIENT_ID'),
            'client_secret' => env('SPOTIFY_CLIENT_SECRET'),
            'market' => env('SPOTIFY_MARKET', 'DE'),
        ],
        'youtube' => [
            'key' => env('YOUTUBE_API_KEY'),
        ],
        'timeout' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Control Panel
    |--------------------------------------------------------------------------
    |
    | The "Smart Links" screen: clicks per song and platform, last `days` days.
    |
    */

    'cp' => [
        'enabled' => true,
        'days' => 30,
    ],

];
