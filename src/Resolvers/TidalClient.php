<?php

namespace Goldnead\Smartlinks\Resolvers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Tidal's catalogue API v2 (JSON:API) with OAuth2 client credentials.
 * Needs TIDAL_CLIENT_ID and TIDAL_CLIENT_SECRET from the Tidal developer
 * portal; cost and terms of commercial use are not known yet.
 *
 * `countryCode` is always sent: without it regionally restricted content
 * may come back as an empty list.
 */
class TidalClient
{
    public const TOKEN_URL = 'https://auth.tidal.com/v1/oauth2/token';

    public const API_URL = 'https://openapi.tidal.com/v2';

    public function configured(): bool
    {
        return filled(config('smartlinks.services.tidal.client_id'))
            && filled(config('smartlinks.services.tidal.client_secret'));
    }

    /**
     * The `data` items of a filtered collection, e.g.
     * `tracks` with `filter[isrc]` or `albums` with `filter[barcodeId]`.
     *
     * @param  array<string, string>  $filter
     * @return list<array<string, mixed>>
     */
    public function filter(string $type, array $filter): array
    {
        $query = [];
        foreach ($filter as $key => $value) {
            $query["filter[{$key}]"] = $value;
        }

        $data = $this->get('/'.$type, $query);

        return array_values(array_filter((array) data_get($data, 'data', []), 'is_array'));
    }

    /**
     * One resource by ID, or null.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $type, string $id): ?array
    {
        $item = data_get($this->get('/'.$type.'/'.rawurlencode($id)), 'data');

        return is_array($item) ? $item : null;
    }

    /**
     * The public link of a track or album resource: the `TIDAL_SHARING`
     * external link when the API gives one, otherwise the canonical form.
     *
     * @param  array<string, mixed>  $item
     */
    public static function linkOf(array $item, string $kind): ?string
    {
        foreach ((array) data_get($item, 'attributes.externalLinks', []) as $link) {
            if (data_get($link, 'meta.type') === 'TIDAL_SHARING' && is_string($href = data_get($link, 'href'))) {
                return $href;
            }
        }

        $id = data_get($item, 'id');

        return is_scalar($id) && preg_match('/^\d+$/', (string) $id) === 1 ? "https://tidal.com/{$kind}/{$id}" : null;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|null
     */
    protected function get(string $path, array $query = []): ?array
    {
        $query['countryCode'] = strtoupper((string) config('smartlinks.country', 'DE'));

        try {
            $response = $this->send($path, $query);

            if ($response?->status() === 401) {
                Cache::forget($this->cacheKey());
                $response = $this->send($path, $query);
            }
        } catch (ConnectionException $e) {
            throw new ServiceError(Resolution::HTTP_ERROR, 'tidal: '.$e->getMessage());
        }

        if ($response === null) {
            throw new ServiceError(Resolution::HTTP_ERROR, 'tidal: no token');
        }

        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            throw ServiceError::http('tidal', $response->status());
        }

        $data = $response->json();

        return is_array($data) ? $data : null;
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
            ->accept('application/vnd.api+json')
            ->get(self::API_URL.$path, $query);
    }

    protected function token(): ?string
    {
        $cached = Cache::get($this->cacheKey());

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::asForm()
            ->withBasicAuth(
                (string) config('smartlinks.services.tidal.client_id'),
                (string) config('smartlinks.services.tidal.client_secret'),
            )
            ->timeout((int) config('smartlinks.services.timeout', 10))
            ->post(self::TOKEN_URL, ['grant_type' => 'client_credentials']);

        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            return null;
        }

        Cache::put($this->cacheKey(), $token, max(60, (int) $response->json('expires_in', 3600) - 60));

        return $token;
    }

    protected function cacheKey(): string
    {
        return 'smartlinks.tidal.token.'.md5((string) config('smartlinks.services.tidal.client_id'));
    }
}
