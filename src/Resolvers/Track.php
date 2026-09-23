<?php

namespace Goldnead\Smartlinks\Resolvers;

/**
 * What the resolvers know about a song. Starts with what the entry holds
 * (a Spotify ID, maybe an ISRC) and is filled in by Spotify's API.
 */
final class Track
{
    public function __construct(
        public ?string $spotifyId = null,
        public ?string $isrc = null,
        public ?string $title = null,
        public ?string $artist = null,
    ) {
        $this->isrc = self::normaliseIsrc($isrc);
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
