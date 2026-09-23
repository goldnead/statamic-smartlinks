<?php

namespace Goldnead\Smartlinks\Resolvers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Apple's iTunes Search API, `lookup`: free, no key.
 *
 * Apple documents about 20 calls a minute, so calls are spaced by
 * `smartlinks.services.itunes.interval_ms` (default 3100). `lookup?isrc=`
 * is not a documented parameter and returns nothing; the way in is the UPC.
 * `country` picks the storefront; without it, links point to the US store.
 */
class ItunesClient
{
    public const API_URL = 'https://itunes.apple.com/lookup';

    protected static ?float $lastCall = null;

    /**
     * @param  array<string, string>  $query
     * @return list<array<string, mixed>>
     */
    public function lookup(array $query): array
    {
        $this->pace();

        try {
            $response = Http::timeout((int) config('smartlinks.services.timeout', 10))
                ->acceptJson()
                ->get(self::API_URL, [...$query, 'country' => strtolower((string) config('smartlinks.country', 'DE'))]);
        } catch (ConnectionException $e) {
            throw new ServiceError(Resolution::HTTP_ERROR, 'itunes: '.$e->getMessage());
        } finally {
            self::$lastCall = microtime(true);
        }

        if ($response->status() === 403 || $response->status() === 429) {
            throw new ServiceError(Resolution::RATE_LIMITED, 'itunes: '.$response->status());
        }

        if (! $response->successful()) {
            throw ServiceError::http('itunes', $response->status());
        }

        // Some failures come back as an HTML page with status 200.
        $results = $response->json('results');

        if (! is_array($results)) {
            throw new ServiceError(Resolution::HTTP_ERROR, 'itunes: not JSON');
        }

        return array_values(array_filter($results, 'is_array'));
    }

    protected function pace(): void
    {
        $interval = (int) config('smartlinks.services.itunes.interval_ms', 3100);

        if ($interval <= 0 || self::$lastCall === null) {
            return;
        }

        $wait = (int) round($interval - (microtime(true) - self::$lastCall) * 1000);

        if ($wait > 0) {
            Sleep::for($wait)->milliseconds();
        }
    }

    public static function resetPacing(): void
    {
        self::$lastCall = null;
    }
}
