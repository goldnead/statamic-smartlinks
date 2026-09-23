<?php

namespace Goldnead\Smartlinks\Resolvers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Spotify's Web API with the client-credentials flow: no user, no consent
 * screen, only public catalogue data. Needs SPOTIFY_CLIENT_ID and
 * SPOTIFY_CLIENT_SECRET.
 */
class SpotifyClient
{
    public const TOKEN_URL = 'https://accounts.spotify.com/api/token';

    public const API_URL = 'https://api.spotify.com/v1';

    public function configured(): bool
    {
        return filled(config('smartlinks.services.spotify.client_id'))
            && filled(config('smartlinks.services.spotify.client_secret'));
    }

    /**
     * Fills ISRC, title and artist from the track. Returns a Resolution
     * reason code: `found`, `not_configured`, `missing_input`, `not_found`
     * or `http_error`.
     */
    public function enrich(Track $track): string
    {
        if ($track->spotifyId === null) {
            return Resolution::MISSING_INPUT;
        }

        if (! $this->configured()) {
            return Resolution::NOT_CONFIGURED;
        }

        try {
            $token = $this->token();

            if ($token === null) {
                return Resolution::HTTP_ERROR;
            }

            $response = Http::withToken($token)
                ->timeout((int) config('smartlinks.services.timeout', 10))
                ->acceptJson()
                ->get(self::API_URL.'/tracks/'.$track->spotifyId, array_filter([
                    'market' => config('smartlinks.services.spotify.market'),
                ]));
        } catch (ConnectionException) {
            return Resolution::HTTP_ERROR;
        }

        if ($response->status() === 404 || $response->status() === 400) {
            return Resolution::NOT_FOUND;
        }

        if (! $response->successful()) {
            if ($response->status() === 401) {
                Cache::forget($this->cacheKey());
            }

            return Resolution::HTTP_ERROR;
        }

        $track->isrc ??= Track::normaliseIsrc($response->json('external_ids.isrc'));
        $track->title ??= $response->json('name');
        $track->artist ??= $response->json('artists.0.name');

        return Resolution::FOUND;
    }

    /**
     * An app token, cached until shortly before it expires.
     */
    protected function token(): ?string
    {
        $cached = Cache::get($this->cacheKey());

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::asForm()
            ->withBasicAuth(
                (string) config('smartlinks.services.spotify.client_id'),
                (string) config('smartlinks.services.spotify.client_secret'),
            )
            ->timeout((int) config('smartlinks.services.timeout', 10))
            ->post(self::TOKEN_URL, ['grant_type' => 'client_credentials']);

        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            return null;
        }

        $ttl = max(60, (int) $response->json('expires_in', 3600) - 60);
        Cache::put($this->cacheKey(), $token, $ttl);

        return $token;
    }

    protected function cacheKey(): string
    {
        return 'smartlinks.spotify.token.'.md5((string) config('smartlinks.services.spotify.client_id'));
    }
}
