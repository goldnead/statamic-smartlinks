# Statamic Smart Links

Smart links for music in Statamic 6. Every song gets a page, `/hoeren/{slug}`, with one button per
platform it is on. Each button goes through `/hoeren/{slug}/{platform}`, which counts the click and
sends the listener on with a 302. The platform is derived from the URL's host, never from a
hand-typed label, and missing links can be filled from Spotify, Deezer and YouTube.

Songs and releases stay ordinary collections of your site. The addon brings behaviour, not a
content model.

Commercial.

## Requirements

- PHP 8.2+, Laravel 12.40+ or 13, Statamic 6

## Install

```bash
composer require goldnead/statamic-smartlinks
php artisan migrate
```

Then tell it where the links are (`php artisan vendor:publish --tag=smartlinks-config`):

```php
'collections' => ['songs'],        // whose entries get a page
'field' => 'streaming_links',      // Grid/Replicator with one URL per row, or a List of URLs
'url_key' => 'url',                // the URL column in a Grid row
'spotify_field' => 'spotify_id',   // a Spotify track ID or URL, for auto-fill
'isrc_field' => null,              // optional; lets Deezer be filled without Spotify credentials
'platform_key' => 'platform',      // where auto-fill writes the platform next to the URL; null = don't
'platform_value' => 'handle',      // `handle` (spotify) or `label` (Spotify)
```

The addon never reads `platform_key` back; it is there for templates that render a row's
`{{ platform }}`. Links are read with inheritance, so a localisation without its own links shows
its origin's, and auto-fill adds new links to the origin.

In the blueprint, give the URL column the **Streaming URL** fieldtype (`smartlink_url`). It shows
the detected platform next to each URL as you type; an unknown host shows as "Other". A
`platform` column you already have is ignored and can go.

## Usage

Put a link to `/hoeren/{slug}` (or `{{ smartlinks:page }}`) wherever you announce the song: bio
link, newsletter, poster QR code. Listeners pick their platform; you see in the Control Panel
which ones they pick.

## The platform comes from the URL

On one band site, 216 of 444 hand-labelled links pointed somewhere else than their label said.
So there is no label. A host matches itself and every subdomain, and the most specific host
wins: `music.youtube.com` is YouTube Music, `youtube.com` is YouTube, `geo.music.apple.com` is
Apple Music, `link.tospotify.com` is Spotify, `apple.com` itself is "other". Short links count:
`spoti.fi` and `spotify.link` are Spotify, `apple.co` is Apple Music. A URL with userinfo
(`https://open.spotify.com@evil.test/`) is not a link at all.

Built in: Spotify, Apple Music, Amazon Music, Amazon (the shop), YouTube Music, YouTube, Tidal,
Deezer, SoundCloud, Bandcamp, Yandex Music, Anghami, Boomplay, Pandora, Napster, Audiomack, Qobuz,
Beatport, KKBOX. More via `'platforms' => ['audiomack' => ['audiomack.com']]`.

**Amazon vs. Amazon Music.** `music.amazon.*` is `amazonmusic`; the shop (`amazon.de/dp/…`,
`amazon.com/gp/…`, `amzn.to`) is its own platform `amazon`, labelled "Amazon". anders-band.de's
Webflow import mapped both to `amazonmusic`; there, a shop link now shows as "Amazon" and its
click URL is `/hoeren/{slug}/amazon`.

One link per platform: when a song has two Spotify links, the first one counts.

The buttons (landing page and `{{ smartlinks:links }}`) follow `smartlinks.priority`, not the
stored row order: Spotify, Apple Music, YouTube Music, Amazon Music, Deezer, Tidal, YouTube,
SoundCloud, Bandcamp, Amazon, then the rest alphabetically, "other" last. Handles may be written
with underscores (`apple_music`).

## Routes

| | |
|---|---|
| `GET /hoeren/{slug}` | the landing page (`noindex`) |
| `GET /hoeren/{slug}/{platform}` | 302 to the stored URL, click counted |

- The redirect only ever goes to a URL stored on the entry. An unknown slug, an unpublished song,
  a platform the song has no link for, or a stored value that is not `http(s)` is a 404.
- No throttle on either route: a concert crowd scanning one QR code shares the venue's IP.
- Prefix and view in `smartlinks.routes`. `SMARTLINKS_ROUTES_ENABLED=false` removes
  both routes; the controller checks again, so a cached route cannot keep them open.
- The page is `smartlinks::landing`, a plain Blade view. Publish it
  (`--tag=smartlinks-views`) or point `smartlinks.routes.view` at an Antlers template; it gets
  `entry`, `title` and `links`.

## Clicks

`smartlinks_clicks` holds one counter per song, platform and day. No IP, no cookie, no user
agent. An upsert on a unique index, so parallel clicks never make a second row.

Not counted, but still redirected: user agents that are empty or contain one of
`smartlinks.clicks.bots` (Googlebot, WhatsApp and Facebook previews, curl, …), HEAD requests,
browser prefetches (`Sec-Purpose`, `Purpose` or `X-Moz: prefetch`), and more than
`smartlinks.clicks.per_minute` (10) clicks per IP, song and platform within a minute. That cap
lives in the cache under a hashed key for a minute; the IP never reaches the database.

Counters older than 400 days (`smartlinks.clicks.prune_days`) go with

```bash
php artisan smartlinks:prune            # or --days=90
```

Schedule it in `routes/console.php`: `Schedule::command('smartlinks:prune')->daily();`

The Control Panel screen **Smart Links** (under Content, permission `view smartlinks`) lists
every song with its clicks per platform over the last 30 days (`smartlinks.cp.days`). Like
core's Entries listing it pages on the server and shows few columns by default (song, clicks,
the busiest platform); the others are under "Customize columns".

## Tags

```antlers
{{ smartlinks:links entry="{id}" }}
    <a href="{{ click_url }}">{{ label }}</a>   {{# platform, url, label, icon, click_url #}}
{{ /smartlinks:links }}

{{ smartlinks:url platform="spotify" }}          {{# the stored URL #}}
{{ smartlinks:page }}                             {{# the landing page URL #}}
```

`entry` takes an ID or slug; without it, the entry in context. `icon` is the platform handle,
for your own icon set. `click_url` is null when the routes are off; fall back to `url`.

## Auto-fill

```bash
php artisan smartlinks:resolve --dry-run      # what would be added
php artisan smartlinks:resolve                # all songs
php artisan smartlinks:resolve alles-wird-gut # one, by slug or ID
```

Only platforms the song has no link for are asked, found links are appended as new rows, and an
existing link is never touched. Every decision is logged (`smartlinks: resolve`) with a reason:
`found`, `already_present` (the song has a link for that platform, the resolver was not asked),
`not_configured`, `missing_input`, `not_found`, `no_confident_match`, `http_error`.

| Resolver | Needs | How |
|---|---|---|
| Spotify | the Spotify ID | the link follows from the ID; with `SPOTIFY_CLIENT_ID` / `SPOTIFY_CLIENT_SECRET` the Web API (client credentials) also supplies ISRC, title, artist |
| Deezer | an ISRC | `api.deezer.com/track/isrc:{ISRC}`, free, no key |
| YouTube | `YOUTUBE_API_KEY`, title, artist | Data API search; only a video from the artist's "… - Topic" channel counts, otherwise nothing |

Apple Music (paid developer account), Amazon, Tidal and the rest stay hand-entered. Odesli /
song.link is not used: its public API was shut down in 2026.

Your own resolver implements `Goldnead\Smartlinks\Contracts\Resolver` (`platform()`,
`resolve(Track): Resolution`) and goes into `smartlinks.resolvers`.

## Development

```bash
composer test                     # SQLite
DB_DRIVER=mysql DB_DATABASE=smartlinks_test composer test:mysql
composer lint && composer analyse
npm run build                     # dist/ is committed
```
