<?php

namespace Goldnead\Smartlinks\Resolvers;

use Goldnead\Smartlinks\Contracts\Resolver;

/**
 * Deezer by ISRC (track) or UPC (release), free and without a key.
 *
 * A track Deezer lists as unavailable in `smartlinks.country` is not linked
 * (`not_available_in_region`).
 */
class DeezerResolver implements Resolver
{
    public function __construct(protected DeezerClient $client, protected Identifier $identifier) {}

    public function platform(): string
    {
        return 'deezer';
    }

    public function resolve(Track $track): Resolution
    {
        if ($track->album ? $track->upc === null : $track->isrc === null) {
            return Resolution::none($this->platform(), Resolution::MISSING_INPUT);
        }

        try {
            if ($track->deezerLink === null) {
                $reason = $track->album
                    ? $this->identifier->absorbDeezerAlbum($track, $this->client->album('upc:'.$track->upc))
                    : $this->identifier->absorbDeezerTrack($track, $this->client->track('isrc:'.$track->isrc));

                if ($reason !== Resolution::FOUND) {
                    return Resolution::none($this->platform(), $reason);
                }
            }
        } catch (ServiceError $e) {
            return Resolution::none($this->platform(), $e->reason);
        }

        if ($track->deezerLink === null) {
            return Resolution::none($this->platform(), Resolution::NOT_FOUND);
        }

        if (! $track->availableIn((string) config('smartlinks.country', 'DE'))) {
            return Resolution::none($this->platform(), Resolution::NOT_AVAILABLE_IN_REGION);
        }

        return Resolution::found($this->platform(), $track->deezerLink);
    }
}
