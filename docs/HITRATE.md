# Hit rate on a real catalogue

Measured 2026-09-23 with `php tests/live/anders-hitrate.php` against the live services, from
goldneros-host (Germany), `smartlinks.country` = DE, without Spotify or Tidal credentials.

Catalogue: the 53 songs of anders-band.de (read only). 39 of them carry a Deezer link; the
chain started from that link alone and compared each service's answer with the link the site
had stored (after link cleanup).

| Service | identical | different, valid | new | missing | wrong | not configured |
|---|---|---|---|---|---|---|
| Deezer | 39 | 0 | 0 | 0 | 0 | 0 |
| Apple Music | 23 | 10 | 6 | 0 | **0** | 0 |
| Spotify | – | – | – | – | – | 39 |
| Tidal | – | – | – | – | – | 39 |

All 39 songs were identified (ISRC from the Deezer track, UPC from its album).

**Apple Music, the 10 "different, valid":**

- 9 songs of "Viel Lärm um dich": the stored links (album `1370926614`) answer **404**; Apple
  re-released the album as `1840984286`, and the resolver found the live links. The dead-link
  check (`smartlinks:check`) flags the stored ones.
- 1 song ("Du fehlst hier"): stored is the single, found is the album track. Both answer 200;
  both are correct.

**6 new:** songs with no Apple link stored got one, each answering 200.

**Wrong: 0.** No link the chain found pointed to another song or failed to answer.

A first run the same morning had 1 Deezer and 2 Apple misses, all Deezer's quota answer
(code 4); since then the Deezer client retries once after a pause, and the second run had none.

Re-run 11:03 after the change that uses Deezer's track position only when its album carries
the entry's UPC: identical numbers (the ANDERS entries hold no UPC of their own, so the UPC
always comes from the Deezer album the position is on).

Spotify and Tidal are not measured: no credentials yet. Their resolvers are covered by tests
with faked responses only.
