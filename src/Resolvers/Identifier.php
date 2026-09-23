<?php

namespace Goldnead\Smartlinks\Resolvers;

use Goldnead\Smartlinks\Platforms;
use Goldnead\Smartlinks\Smartlinks;
use Statamic\Entries\Entry;

/**
 * Finds the exact identity of a song (ISRC) or release (UPC) from whatever
 * the entry already holds: the ISRC/UPC fields, a Spotify ID, or a link of
 * any service whose API gives the identifier back (Deezer free; Spotify and
 * Tidal with credentials). Once the ISRC is known, Deezer adds the rest: its
 * own link, the album and from it the UPC, track and disc number, length and
 * the countries the track is available in.
 *
 * Never by name. A song without any of these stays unidentified, and the
 * resolvers that need a key report `missing_input`.
 */
class Identifier
{
    public function __construct(
        protected Smartlinks $smartlinks,
        protected DeezerClient $deezer,
        protected SpotifyClient $spotify,
        protected TidalClient $tidal,
    ) {}

    public function identify(Entry $entry): Track
    {
        $spotifyField = config('smartlinks.spotify_field');
        $isrcField = config('smartlinks.isrc_field');
        $upcField = config('smartlinks.upc_field');

        return $this->fromUrls(
            $this->smartlinks->storedUrls($entry),
            album: $this->smartlinks->isRelease($entry),
            isrc: $isrcField ? $this->string($entry->value($isrcField)) : null,
            upc: $upcField ? $this->string($entry->value($upcField)) : null,
            spotify: $spotifyField ? $this->string($entry->value($spotifyField)) : null,
        );
    }

    /**
     * A Track from stored URLs and known identifiers, before any request.
     *
     * @param  list<string>  $urls
     */
    public function fromUrls(array $urls, bool $album = false, ?string $isrc = null, ?string $upc = null, ?string $spotify = null): Track
    {
        $kind = $album ? 'album' : 'track';
        $ids = ['spotify' => self::idFrom($spotify ?? '', 'spotify', $kind)];
        $platforms = app(Platforms::class);

        foreach ($urls as $url) {
            $platform = $platforms->detect($url);

            if (in_array($platform, ['spotify', 'deezer', 'tidal'], true)) {
                $ids[$platform] ??= self::idFrom($url, $platform, $kind);
            }
        }

        return new Track(
            spotifyId: $ids['spotify'] ?? null,
            isrc: $isrc,
            upc: $upc,
            deezerId: $ids['deezer'] ?? null,
            tidalId: $ids['tidal'] ?? null,
            album: $album,
        );
    }

    /**
     * The service's own ID of a track or album in a URL (or a bare Spotify
     * ID / URI), or null.
     */
    public static function idFrom(string $value, string $platform, string $kind): ?string
    {
        $value = trim($value);

        if ($platform === 'spotify') {
            if ($kind === 'track') {
                return Track::spotifyIdFrom($value);
            }

            if (preg_match('/^[A-Za-z0-9]{22}$/', $value) === 1) {
                return $value;
            }

            return preg_match('~(?:open\.spotify\.com/(?:intl-[a-z]{2}(?:-[a-z]{2})?/)?album/|^spotify:album:)([A-Za-z0-9]{22})~i', $value, $m) === 1 ? $m[1] : null;
        }

        if (! str_contains($value, '://')) {
            return null;
        }

        $path = (string) parse_url($value, PHP_URL_PATH);

        return preg_match('~/'.$kind.'/(\d+)(?:/|$)~', $path, $m) === 1 ? $m[1] : null;
    }

    /**
     * Fills the Track from the services. Returns `found` when the key (ISRC
     * for a song, UPC for a release) is known afterwards, otherwise the
     * reason of the last failed step, or `missing_input` when there was
     * nothing to start from.
     */
    public function enrich(Track $track): string
    {
        $failure = Resolution::MISSING_INPUT;
        $attempt = function (callable $step) use (&$failure): void {
            try {
                $reason = $step();

                if (is_string($reason) && $reason !== Resolution::FOUND && $reason !== Resolution::MISSING_INPUT) {
                    $failure = $reason;
                }
            } catch (ServiceError $e) {
                $failure = $e->reason;
            }
        };

        if ($track->album) {
            $this->enrichAlbum($track, $attempt);

            return $track->upc !== null ? Resolution::FOUND : $failure;
        }

        if ($track->isrc === null && $track->deezerId !== null) {
            $attempt(fn () => $this->absorbDeezerTrack($track, $this->deezer->track($track->deezerId)));
        }

        if ($track->isrc === null && $track->spotifyId !== null) {
            $attempt(fn () => $this->spotify->enrich($track));
        }

        if ($track->isrc === null && $track->tidalId !== null && $this->tidal->configured()) {
            $attempt(function () use ($track) {
                $track->isrc = Track::normaliseIsrc(data_get($this->tidal->find('tracks', $track->tidalId), 'attributes.isrc'));

                return $track->isrc === null ? Resolution::NOT_FOUND : Resolution::FOUND;
            });
        }

        if ($track->isrc !== null && $track->deezerLink === null) {
            $attempt(fn () => $this->absorbDeezerTrack($track, $this->deezer->track('isrc:'.$track->isrc)));
        }

        if ($track->upc === null && $track->deezerAlbumId !== null) {
            $attempt(function () use ($track) {
                $track->upc = Track::normaliseUpc($this->string(data_get($this->deezer->album((string) $track->deezerAlbumId), 'upc')));

                return $track->upc === null ? Resolution::NOT_FOUND : Resolution::FOUND;
            });
        }

        return $track->isrc !== null ? Resolution::FOUND : $failure;
    }

    protected function enrichAlbum(Track $track, callable $attempt): void
    {
        if ($track->upc === null && $track->deezerId !== null) {
            $attempt(fn () => $this->absorbDeezerAlbum($track, $this->deezer->album($track->deezerId)));
        }

        if ($track->upc === null && $track->spotifyId !== null) {
            $attempt(fn () => $this->spotify->enrich($track));
        }

        if ($track->upc === null && $track->tidalId !== null && $this->tidal->configured()) {
            $attempt(function () use ($track) {
                $track->upc = Track::normaliseUpc($this->string(data_get($this->tidal->find('albums', $track->tidalId), 'attributes.barcodeId')));

                return $track->upc === null ? Resolution::NOT_FOUND : Resolution::FOUND;
            });
        }

        if ($track->upc !== null && $track->deezerLink === null) {
            $attempt(fn () => $this->absorbDeezerAlbum($track, $this->deezer->album('upc:'.$track->upc)));
        }
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    public function absorbDeezerTrack(Track $track, ?array $data): string
    {
        if ($data === null) {
            return Resolution::NOT_FOUND;
        }

        $isrc = Track::normaliseIsrc($this->string($data['isrc'] ?? null));

        // Looked up by ISRC, the answer must carry that ISRC.
        if ($track->isrc !== null && $isrc !== null && $isrc !== $track->isrc) {
            return Resolution::MISMATCH;
        }

        $track->isrc ??= $isrc;
        $track->deezerId ??= isset($data['id']) ? (string) $data['id'] : null;
        $link = $this->string($data['link'] ?? null);
        $track->deezerLink = $link !== null && app(Platforms::class)->detect($link) === 'deezer' ? $link : null;
        $track->deezerAlbumId = is_numeric(data_get($data, 'album.id')) ? (int) data_get($data, 'album.id') : $track->deezerAlbumId;
        $track->trackNumber = is_numeric($data['track_position'] ?? null) ? (int) $data['track_position'] : $track->trackNumber;
        $track->discNumber = is_numeric($data['disk_number'] ?? null) ? (int) $data['disk_number'] : $track->discNumber;
        $track->duration = is_numeric($data['duration'] ?? null) ? (int) $data['duration'] : $track->duration;
        $track->title ??= $this->string($data['title'] ?? null);
        $track->artist ??= $this->string(data_get($data, 'artist.name'));

        if (is_array($data['available_countries'] ?? null)) {
            $track->availableCountries = array_values(array_map(fn ($c) => strtoupper((string) $c), $data['available_countries']));
        }

        return Resolution::FOUND;
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    public function absorbDeezerAlbum(Track $track, ?array $data): string
    {
        if ($data === null) {
            return Resolution::NOT_FOUND;
        }

        $upc = Track::normaliseUpc($this->string($data['upc'] ?? null));

        if ($track->upc !== null && $upc !== null && $upc !== $track->upc) {
            return Resolution::MISMATCH;
        }

        $track->upc ??= $upc;
        $track->deezerId ??= isset($data['id']) ? (string) $data['id'] : null;
        $link = $this->string($data['link'] ?? null);
        $track->deezerLink = $link !== null && app(Platforms::class)->detect($link) === 'deezer' ? $link : null;
        $track->title ??= $this->string($data['title'] ?? null);
        $track->artist ??= $this->string(data_get($data, 'artist.name'));

        if (is_array($data['available_countries'] ?? null)) {
            $track->availableCountries = array_values(array_map(fn ($c) => strtoupper((string) $c), $data['available_countries']));
        }

        return Resolution::FOUND;
    }

    protected function string(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
