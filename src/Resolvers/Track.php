<?php

namespace Goldnead\Smartlinks\Resolvers;

/**
 * What the resolvers know about a song or a release. Starts with what the
 * entry holds (a link or ID of any supported service, maybe an ISRC or UPC)
 * and is filled in by {@see Identifier}: the ISRC identifies a recording,
 * the UPC its release. Resolvers write only on those, never on a name.
 *
 * `$album` is true for an entry of a release collection: then the UPC is
 * the key and the resolvers look for the album, not a track.
 */
final class Track
{
    /** @var list<string>|null ISO country codes, from Deezer */
    public ?array $availableCountries = null;

    public ?string $deezerLink = null;

    public ?int $deezerAlbumId = null;

    public ?int $trackNumber = null;

    public ?int $discNumber = null;

    /** Seconds. */
    public ?int $duration = null;

    public function __construct(
        public ?string $spotifyId = null,
        public ?string $isrc = null,
        public ?string $title = null,
        public ?string $artist = null,
        public ?string $upc = null,
        public ?string $deezerId = null,
        public ?string $tidalId = null,
        public bool $album = false,
    ) {
        $this->isrc = self::normaliseIsrc($isrc);
        $this->upc = self::normaliseUpc($upc);
    }

    /**
     * The key a release is known by: UPC-A (12 digits) or EAN-13, digits only.
     */
    public static function normaliseUpc(?string $upc): ?string
    {
        $upc = preg_replace('/\D/', '', trim((string) $upc));

        return preg_match('/^\d{12,14}$/', (string) $upc) === 1 ? $upc : null;
    }

    /**
     * Whether the track may be linked in `$country`. Unknown availability
     * counts as available: only a service's explicit list can rule it out.
     */
    public function availableIn(string $country): bool
    {
        return $this->availableCountries === null
            || in_array(strtoupper($country), $this->availableCountries, true);
    }

    /**
     * The track ID from a bare ID, a `spotify:track:` URI or an
     * open.spotify.com track URL (with or without an `intl-xx` segment).
     */
    public static function spotifyIdFrom(?string $value): ?string
    {
        $value = trim((string) $value);

        if (preg_match('/^[A-Za-z0-9]{22}$/', $value) === 1) {
            return $value;
        }

        if (preg_match('~^spotify:track:([A-Za-z0-9]{22})$~', $value, $m) === 1) {
            return $m[1];
        }

        if (preg_match('~^https?://open\.spotify\.com/(?:intl-[a-z]{2}(?:-[a-z]{2})?/)?(?:embed/)?track/([A-Za-z0-9]{22})(?:[/?#]|$)~i', $value, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * An ISRC in its twelve-character form, or null. Validated because it
     * becomes part of a request path.
     */
    public static function normaliseIsrc(?string $isrc): ?string
    {
        $isrc = strtoupper(str_replace(['-', ' '], '', trim((string) $isrc)));

        return preg_match('/^[A-Z]{2}[A-Z0-9]{3}[0-9]{7}$/', $isrc) === 1 ? $isrc : null;
    }
}
