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
| `GET /hoeren/{slug}` | a song's landing page (`noindex`) |
| `GET /hoeren/{slug}/{platform}` | 302 to the stored URL, click counted |
| `GET /hoeren/release/{slug}` (and `/{platform}`) | the same for a release |

**One segment per collection.** Songs sit at the prefix, collections in `release_collections`
under `release/`, so a song and its single can share a slug (on anders-band.de five pairs do,
e.g. `alles-wird-gut`). `smartlinks.routes.segments` sets it per collection, e.g.
`['releases' => 'album', 'songs' => '']`; an empty segment mounts at the prefix. A slug is
looked up only in the collections of its segment, one after the other in config order, so two
collections at the same segment always resolve the same way. `{{ smartlinks:page }}`,
`click_url` and the CP link each entry on its own route. A song whose slug equals a segment
(`release`) is shadowed by that segment's routes.

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
php artisan smartlinks:resolve --replace-dead  # also replace confirmed dead links
```

Every platform the song holds a link for counts as present, dead or not: resolve never adds a
second one. With `--replace-dead` (off by default), a platform whose links are all confirmed
dead (two dead checks in a row, see [Dead links](#dead-links)) is asked again, and a found link
goes into the dead link's row, its other columns kept; the dead link's check history goes.
`suspect` and `unknown` links are never touched. Each replacement is logged
(`smartlinks: replaced dead link`, old and new URL). A nightly pair:
`smartlinks:check` at 03:30, `smartlinks:resolve --replace-dead` at 04:30.

**Exact, never by name.** First the song's identity: its ISRC (a release: its UPC). It comes from
the `isrc_field`/`upc_field`, or from any link whose service gives it back: a Deezer link (free),
a Spotify link or `spotify_field` (with Spotify credentials), a Tidal link (with Tidal
credentials). With the ISRC, Deezer adds the album and from it the UPC, track and disc number,
length and the countries the track is available in. Every resolver then asks its service for
exactly that key. When the blueprint has the `isrc_field`/`upc_field`, the found ISRC and UPC
are stored there and the next run starts from them.

Only platforms the song has no link for are asked, found links are appended as new rows, and an
existing link is never touched. One service failing only costs that service. Every decision is
logged (`smartlinks: resolve`) with a reason: `found`, `already_present` (the song has a link
for that platform, the resolver was not asked), `already_suggested`, `suggested`,
`not_configured`, `missing_input` (no ISRC/UPC), `not_found`, `not_available_in_region`,
`mismatch` (an answer whose ISRC, UPC, position or length disagrees), `no_confident_match`,
`rate_limited`, `http_error`.

| Resolver | Needs | How |
|---|---|---|
| Spotify | Spotify ID, or ISRC/UPC + credentials | the link follows from the ID; else `search?q=isrc:…` (tracks) or `q=upc:…&type=album`, `market` = `smartlinks.country`; token cached |
| Deezer | ISRC or UPC | `track/isrc:{ISRC}`, `album/upc:{UPC}`, free, no key; not linked where `available_countries` lacks the country |
| Apple Music | UPC (from Deezer) | iTunes `lookup?upc=…&entity=song&country=de`, the track by track and disc number, checked against the length (±10 s); calls spaced to stay under Apple's ~20 a minute |
| Tidal | ISRC or UPC + `TIDAL_CLIENT_ID`/`SECRET` | `tracks?filter[isrc]=`, `albums?filter[barcodeId]=`, `countryCode` = country; prefers the `TIDAL_SHARING` link |
| YouTube | `YOUTUBE_API_KEY`, title, artist | name search, so **a suggestion only**: stored as pending and accepted or rejected in the CP, never written by itself. Only a video from the artist's "… - Topic" channel is suggested |

Amazon, Boomplay, Napster, Yandex and the rest stay hand-entered. Odesli / song.link is not
used: its public API was shut down in 2026.

**Releases.** Collections in `release_collections` get a page like songs and are resolved by
UPC: Deezer album, Apple Music album, Spotify album, Tidal album. A Deezer album link on the
release is enough to start.

Your own resolver implements `Goldnead\Smartlinks\Contracts\Resolver` (`platform()`,
`resolve(Track): Resolution`) and goes into `smartlinks.resolvers`; a name-matching one
implements `Contracts\SuggestsOnly` so its finds wait for review.

## Link cleanup

On save, and for existing data with `php artisan smartlinks:clean --dry-run`, every link loses
somebody else's affiliate and tracking parameters (Apple `at`, `ct`, `uo`, `app`, `itsct`,
`itscg`, `ls`, `mt`; `utm_*`; Spotify `si`; `fbclid`, `gclid`) and gets one canonical form:
`music.apple.com` instead of `geo.`/`itunes.`, no `id` prefix, Deezer without the language
segment, `tidal.com/track/…` instead of `listen.tidal.com/browse/…/u`, `spotify:track:` URIs as
URLs. The ANDERS Apple links all carried Odesli's affiliate token: the commission went to Odesli.
`cleanup.keep` protects parameters (your own token), `cleanup.strip` adds more,
`cleanup.on_save` switches the save hook off.

## Dead links

```bash
php artisan smartlinks:check --dry-run   # report only
php artisan smartlinks:check             # record in smartlinks_link_status
```

Schedule it nightly: `Schedule::command('smartlinks:check')->dailyAt('03:30');`. HEAD first, GET
when HEAD is refused; one request per host and second. Only 404 or 410 counts as a dead answer.
A 403 wall, 429, 5xx, timeout, connection error or a host that does not resolve is "unknown",
never dead. A link is **confirmed** dead on the second dead check in a row (`suspect` after the
first); `ok` resets that. Confirmed dead links are left off the landing page and the tags
(`check.hide_dead`), so a second link of the same platform or the next `smartlinks:resolve`
takes over. The CP shows them per song, filter "Dead links".

The check never fetches a private, loopback, link-local, reserved or cloud-metadata address
(IPv4 and IPv6): each host is resolved first, redirects are followed by hand (at most five) and
checked hop by hop, and the connection is pinned to the checked address. Bind your own
`Goldnead\Smartlinks\Contracts\HostResolver` if the server needs a different DNS policy.

## Control Panel

Per song: clicks, and as badges in the title cell the dead links and open suggestions (also
available as columns). Filter "Link state": dead links, open suggestions. The row menu shows a
suggestion, accepts it into the links or rejects it for good; that needs `manage smartlinks`.

## Limits

- **Deezer**: the `isrc:`/`upc:` paths are public but not documented; they can go away. Deezer's
  developer FAQ allows commercial use only with an agreement: clear this before selling to
  clients.
- **Tidal**: needs an app in the Tidal developer portal. Cost and terms for commercial use are
  not known yet.
- **Spotify**: since February 2026 an app in Development Mode needs a Premium account as owner,
  returns at most 10 search results and allows 5 users; extended quota only for organisations
  with 250k monthly users. Lookups without users, as here, are fine.
- **Apple Music**: through the free iTunes Search API, about 20 calls a minute, no ISRC lookup.
  A song whose release Deezer does not have, or has without UPC, gets no Apple link.
- **YouTube**: Data API quota 100 searches a day, name search only, hence suggestions.
- Measured hit rate on a real catalogue: `docs/HITRATE.md`.

## Development

```bash
composer test                     # SQLite
DB_DRIVER=mysql DB_DATABASE=smartlinks_test composer test:mysql
composer lint && composer analyse
npm run build                     # dist/ is committed
php tests/live/anders-hitrate.php # live, against the real services; not in CI
```
