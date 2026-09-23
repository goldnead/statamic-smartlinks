<?php

namespace Goldnead\Smartlinks\Resolvers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Deezer's public catalogue API, free and without a key.
 *
 * `track/isrc:{ISRC}` and `album/upc:{UPC}` work but are not documented by
 * Deezer: plan for them to go away, and treat an answer as data from outside.
 * A miss is HTTP 200 with `{"error": {"code": 800}}`; code 4 is the quota,
 * 700 an overloaded service. Commercial use needs an agreement with Deezer
 * (their developer FAQ).
 */
class DeezerClient
{
    public const API_URL = 'https://api.deezer.com';

    /**
     * @return array<string, mixed>|null null when Deezer has no such track
     */
    public function track(string $idOrIsrc): ?array
    {
        return $this->get('track/'.$idOrIsrc);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function album(string $idOrUpc): ?array
    {
        return $this->get('album/'.$idOrUpc);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function get(string $path): ?array
    {
        // Deezer's quota (code 4) and "busy" (700) pass within a second or
        // two: one retry after a pause, then the error stands. Measured on
        // the ANDERS catalogue, where 2 of 39 songs fell through without it.
        try {
            return $this->attempt($path);
        } catch (ServiceError $e) {
            if ($e->reason !== Resolution::RATE_LIMITED) {
                throw $e;
            }

            Sleep::for((int) config('smartlinks.services.deezer.retry_ms', 1500))->milliseconds();

            return $this->attempt($path);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function attempt(string $path): ?array
    {
        try {
            $response = Http::timeout((int) config('smartlinks.services.timeout', 10))
                ->acceptJson()
                ->get(self::API_URL.'/'.$path);
        } catch (ConnectionException $e) {
            throw new ServiceError(Resolution::HTTP_ERROR, 'deezer: '.$e->getMessage());
        }

        if (! $response->successful()) {
            throw ServiceError::http('deezer', $response->status());
        }

        $error = $response->json('error');

        if (is_array($error)) {
            return match ((int) ($error['code'] ?? 0)) {
                800 => null,
                4, 700 => throw new ServiceError(Resolution::RATE_LIMITED, 'deezer: quota or busy'),
                default => throw new ServiceError(Resolution::HTTP_ERROR, 'deezer: '.($error['message'] ?? 'error')),
            };
        }

        $data = $response->json();

        return is_array($data) ? $data : null;
    }
}
