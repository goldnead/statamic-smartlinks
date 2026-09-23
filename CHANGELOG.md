# Changelog

## Unreleased

First version.

### Added
- Landing page per song (`/hoeren/{slug}`) and a counting redirect (`/hoeren/{slug}/{platform}`),
  behind `SMARTLINKS_ROUTES_ENABLED`. Redirects only to URLs stored on the entry.
- Platform detection from the URL host (18 platforms, extendable via config); the most specific
  host wins. Fieldtype `smartlink_url` shows the detected platform in the Control Panel.
- Click counting per song, platform and day in `smartlinks_clicks`, no personal data; bots,
  link previews and HEAD requests are not counted.
- Control Panel screen "Smart Links" with clicks per platform over the last 30 days,
  permission `view smartlinks`.
- `php artisan smartlinks:resolve {entry?} --dry-run`: fills missing links from Spotify (ID,
  Web API with client credentials), Deezer (ISRC) and YouTube (Data API, Topic channel only).
  Never overwrites; logs every decision with a reason code.
- Antlers tags `smartlinks:links`, `smartlinks:url`, `smartlinks:page`.
- German and English translations.
