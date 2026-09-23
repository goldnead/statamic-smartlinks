<?php

namespace Goldnead\Smartlinks\Resolvers;

use Goldnead\Smartlinks\Contracts\Resolver;
use Goldnead\Smartlinks\Platforms;

/**
 * Spotify. With a known Spotify ID the link follows from it, no API. Else,
 * with credentials, a search on the exact key: `isrc:` (tracks) or `upc:`
 * (albums; Spotify accepts `upc:` only with `type=album`).
 */
class SpotifyResolver implements Resolver
{
    public function __construct(protected SpotifyClient $client) {}

    public function platform(): string
    {
        return 'spotify';
    }

    public function resolve(Track $track): Resolution
    {
        if ($track->spotifyId !== null) {
            return Resolution::found($this->platform(), 'https://open.spotify.com/'.($track->album ? 'album' : 'track').'/'.$track->spotifyId);
        }

        $key = $track->album ? $track->upc : $track->isrc;

        if ($key === null) {
            return Resolution::none($this->platform(), Resolution::MISSING_INPUT);
        }

        if (! $this->client->configured()) {
            return Resolution::none($this->platform(), Resolution::NOT_CONFIGURED);
        }

        try {
            $item = $track->album
                ? $this->client->search('album', 'upc:'.$key)
                : $this->client->search('track', 'isrc:'.$key);
        } catch (ServiceError $e) {
            return Resolution::none($this->platform(), $e->reason);
        }

        if ($item === null) {
            return Resolution::none($this->platform(), Resolution::NOT_FOUND);
        }

        $isrc = data_get($item, 'external_ids.isrc');
        if (! $track->album && is_string($isrc) && Track::normaliseIsrc($isrc) !== $key) {
            return Resolution::none($this->platform(), Resolution::MISMATCH);
        }

        $url = data_get($item, 'external_urls.spotify');

        return is_string($url) && app(Platforms::class)->detect($url) === 'spotify'
            ? Resolution::found($this->platform(), $url)
            : Resolution::none($this->platform(), Resolution::NOT_FOUND);
    }
}
