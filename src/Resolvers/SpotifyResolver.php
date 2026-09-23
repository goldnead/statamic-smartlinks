<?php

namespace Goldnead\Smartlinks\Resolvers;

use Goldnead\Smartlinks\Contracts\Resolver;

/**
 * The Spotify link itself needs no API: it follows from the track ID.
 */
class SpotifyResolver implements Resolver
{
    public function platform(): string
    {
        return 'spotify';
    }

    public function resolve(Track $track): Resolution
    {
        if ($track->spotifyId === null) {
            return Resolution::none($this->platform(), Resolution::MISSING_INPUT);
        }

        return Resolution::found($this->platform(), 'https://open.spotify.com/track/'.$track->spotifyId);
    }
}
