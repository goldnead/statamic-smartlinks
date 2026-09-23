<?php

namespace Goldnead\Smartlinks\Resolvers;

use Goldnead\Smartlinks\Contracts\Resolver;
use Goldnead\Smartlinks\Platforms;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Deezer's public API looks a track up by ISRC, free and without a key.
 *
 * A miss is not an HTTP error: Deezer answers 200 with
 * `{"error": {"type": "DataException", "message": "no data", "code": 800}}`.
 */
class DeezerResolver implements Resolver
{
    public const API_URL = 'https://api.deezer.com';

    public function platform(): string
    {
        return 'deezer';
    }

    public function resolve(Track $track): Resolution
    {
        if ($track->isrc === null) {
            return Resolution::none($this->platform(), Resolution::MISSING_INPUT);
        }

        try {
            $response = Http::timeout((int) config('smartlinks.services.timeout', 10))
                ->acceptJson()
                ->get(self::API_URL.'/track/isrc:'.$track->isrc);
        } catch (ConnectionException) {
            return Resolution::none($this->platform(), Resolution::HTTP_ERROR);
        }

        if (! $response->successful()) {
            return Resolution::none($this->platform(), Resolution::HTTP_ERROR);
        }

        if ($response->json('error') !== null) {
            return Resolution::none($this->platform(), Resolution::NOT_FOUND);
        }

        $link = $response->json('link');

        // Only a link that really is Deezer's: the answer is data from outside.
        if (! is_string($link) || app(Platforms::class)->detect($link) !== $this->platform()) {
            return Resolution::none($this->platform(), Resolution::NOT_FOUND);
        }

        return Resolution::found($this->platform(), $link);
    }
}
