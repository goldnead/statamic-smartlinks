# Changelog

## 0.1.0 (2026-09-23)

First version.

### Added
- Landing page per song (`/hoeren/{slug}`) and a counting redirect (`/hoeren/{slug}/{platform}`),
  behind `SMARTLINKS_ROUTES_ENABLED`. Redirects only to http(s) URLs stored on the entry, never
  to one with userinfo. Not throttled (shared venue IPs at concerts).
- Platform detection from the URL host (19 platforms incl. short links `spoti.fi`,
  `spotify.link`, `apple.co`; Amazon shop and Amazon Music apart), extendable via config; the
  most specific host wins. Fieldtype `smartlink_url` shows the detected platform in the CP.
- Click counting per song, platform and day in `smartlinks_clicks`, no personal data; bots,
  link previews, HEAD requests and browser prefetches are not counted, and at most
  `clicks.per_minute` per IP, song and platform.
- `php artisan smartlinks:prune --days=` (default 400), schedulable.
- Control Panel screen "Smart Links" with clicks per platform over the last 30 days,
  permission `view smartlinks`.
- `php artisan smartlinks:resolve {entry?} --dry-run`: fills missing links from Spotify (ID,
  Web API with client credentials, one retry with a fresh token after a 401), Deezer (ISRC) and
  YouTube (Data API, Topic channel only). Never overwrites; writes the platform under
  `platform_key`; adds to the origin when a localisation inherits the links; logs every
  decision with a reason code.
- Links read with inheritance (localisations), slugs resolved in the current site.
- Antlers tags `smartlinks:links`, `smartlinks:url`, `smartlinks:page`.
- German and English translations.
