<?php

namespace Goldnead\Smartlinks\Resolvers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Spotify's Web API with the client-credentials flow: no user, no consent
 * screen, only public catalogue data. Needs SPOTIFY_CLIENT_ID and
 * SPOTIFY_CLIENT_SECRET.
 *
 * Since February 2026 an app in Development Mode needs a Premium account
 * as owner and gets at most 10 search results; `market` is always sent,
 * without it catalogue content counts as unavailable.
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
     * Fills ISRC (or, for a release, UPC), title and artist from the
     * track or album. Returns a Resolution reason code: `found`,
     * `not_configured`, `missing_input`, `not_found`, `rate_limited` or
     * `http_error`.
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
            $data = $this->get(($track->album ? '/albums/' : '/tracks/').$track->spotifyId);
        } catch (ServiceError $e) {
            return $e->reason;
        }

        if ($data === null) {
            return Resolution::NOT_FOUND;
        }

        if ($track->album) {
            $track->upc ??= Track::normaliseUpc(data_get($data, 'external_ids.upc') ?? data_get($data, 'external_ids.ean'));
        } else {
            $track->isrc ??= Track::normaliseIsrc(data_get($data, 'external_ids.isrc'));
        }

        $track->title ??= data_get($data, 'name');
        $track->artist ??= data_get($data, 'artists.0.name');

        return Resolution::FOUND;
    }

    /**
     * The first search hit for `isrc:…` (type track) or `upc:…` (type album),
     * or null.
     *
     * @return array<string, mixed>|null
     */
    public function search(string $type, string $query): ?array
    {
        $data = $this->get('/search', [
            'q' => $query,
            'type' => $type,
            'limit' => 5,
        ]);

        $item = data_get($data, $type.'s.items.0');

        return is_array($item) ? $item : null;
    }

    /**
     * A GET with the app token; after a 401 once more with a fresh token.
     * null for 404/400.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|null
     */
    public function get(string $path, array $query = []): ?array
    {
        $query = array_filter([...$query, 'market' => $this->market()]);

        try {
            $response = $this->send($path, $query);

            // A cached token can die before its expiry (revoked, rotated
            // secret). Forget it and try once more with a fresh one.
            if ($response?->status() === 401) {
                Cache::forget($this->cacheKey());
                $response = $this->send($path, $query);

                if ($response?->status() === 401) {
                    Cache::forget($this->cacheKey());
                }
            }
        } catch (ConnectionException $e) {
            throw new ServiceError(Resolution::HTTP_ERROR, 'spotify: '.$e->getMessage());
        }

        if ($response === null) {
            throw new ServiceError(Resolution::HTTP_ERROR, 'spotify: no token');
        }

        if ($response->status() === 404 || $response->status() === 400) {
            return null;
        }

        if (! $response->successful()) {
            throw ServiceError::http('spotify', $response->status());
        }

        $data = $response->json();

        return is_array($data) ? $data : null;
    }

    protected function market(): ?string
    {
        $market = config('smartlinks.services.spotify.market') ?: config('smartlinks.country');

        return is_string($market) && $market !== '' ? strtoupper($market) : null;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    protected function send(string $path, array $query): ?Response
    {
        $token = $this->token();

        if ($token === null) {
            return null;
        }

        return Http::withToken($token)
            ->timeout((int) config('smartlinks.services.timeout', 10))
            ->acceptJson()
            ->get(self::API_URL.$path, $query);
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
