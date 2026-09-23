# Changelog

## 0.2.0 (unreleased)

### Added
- Exact identification: ISRC (songs) or UPC (releases) from the entry's fields or from any
  Deezer, Spotify or Tidal link; Deezer adds album, UPC, position, length and availability.
  Stored in `isrc_field`/`upc_field` when the blueprint has them.
- Resolvers on that key only: Apple Music (Deezer UPC → iTunes lookup, track by position,
  length checked, paced to ~20 calls a minute), Spotify search `isrc:`/`upc:`, Deezer
  `album/upc:`, Tidal (`filter[isrc]`, `filter[barcodeId]`, `TIDAL_SHARING` link).
- Region: `smartlinks.country` (default DE) for all storefronts; Deezer tracks unavailable
  there are not linked.
- `release_collections`: releases get a page and are resolved as albums by UPC.
- YouTube finds are suggestions (`smartlinks_suggestions`), accepted or rejected in the CP
  (permission `manage smartlinks`), never written by themselves; a rejected URL is not
  suggested again.
- Link cleanup on save and `smartlinks:clean --dry-run`: foreign affiliate and tracking
  parameters removed, URL forms normalised, `cleanup.keep`/`cleanup.strip`.
- `smartlinks:check --dry-run`: dead-link check (HEAD, GET fallback, per-host pacing), table
  `smartlinks_link_status`, dead links hidden on the landing page, badges and filter in the CP.
- Reason codes `rate_limited`, `mismatch`, `not_available_in_region`, `suggested`,
  `already_suggested`.
- Live hit-rate harness `tests/live/anders-hitrate.php` (not in CI), results in `docs/HITRATE.md`.

### Security
- Link check: every hop's host is resolved and refused when any address is private, loopback,
  link-local, reserved, multicast or cloud metadata (IPv4 and IPv6, `169.254.169.254`, `::1`,
  `fc00::/7`, v4-mapped, NAT64); redirects are followed by hand (at most 5), each hop checked;
  the connection is pinned to the checked address. Resolver bindable (`Contracts\HostResolver`).

### Changed
- The resolver chain identifies before it resolves; a failing service no longer affects the
  others.
- Link check: DNS and connection errors are `unknown`, never `dead`; a link is dead only after
  two dead checks in a row (`dead_streak`, status `suspect` in between); `hide_dead` hides
  confirmed dead links only.
- Apple Music uses Deezer's track position only when that Deezer album carries the entry's UPC
  (compilations, other editions): otherwise `no_confident_match`.
- Cleanup splits the query raw (unchanged URLs stay byte for byte), drops rows that are
  duplicates after cleaning, and carries check history to the cleaned URL (orphans removed).
- A suggested platform is not searched again after its suggestion was rejected; accepting a
  suggestion for a platform that has a link meanwhile drops it (`superseded`).
- Tidal accepts only an item with exactly the asked ISRC or UPC.
- `release_collections` are part of `Smartlinks::collections()`.

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
  permission `view smartlinks`; server-side listing with core's paginator footer, few columns
  visible by default so it fits a phone.
- Buttons ordered by `smartlinks.priority` (Spotify, Apple Music, YouTube Music, …), not by
  stored row order.
- `php artisan smartlinks:resolve {entry?} --dry-run`: fills missing links from Spotify (ID,
  Web API with client credentials, one retry with a fresh token after a 401), Deezer (ISRC) and
  YouTube (Data API, Topic channel only). Never overwrites; writes the platform under
  `platform_key`; adds to the origin when a localisation inherits the links; logs every
  decision with a reason code.
- Links read with inheritance (localisations), slugs resolved in the current site.
- Antlers tags `smartlinks:links`, `smartlinks:url`, `smartlinks:page`.
- German and English translations.
