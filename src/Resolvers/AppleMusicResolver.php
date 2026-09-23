<?php

namespace Goldnead\Smartlinks\Resolvers;

use Goldnead\Smartlinks\Contracts\Resolver;
use Goldnead\Smartlinks\LinkCleaner;
use Goldnead\Smartlinks\Platforms;

/**
 * Apple Music without a key: the release's UPC (from Deezer) into the
 * iTunes `lookup?upc=…&entity=song`, then the track inside that release by
 * its track and disc number (from Deezer, which found it by ISRC), checked
 * against the length within `smartlinks.services.itunes.duration_tolerance`
 * seconds. Apple's lookup has no ISRC parameter.
 *
 * Measured on the ANDERS catalogue before building: 33 of 33 right.
 */
class AppleMusicResolver implements Resolver
{
    public function __construct(protected ItunesClient $client, protected LinkCleaner $cleaner) {}

    public function platform(): string
    {
        return 'applemusic';
    }

    public function resolve(Track $track): Resolution
    {
        if ($track->upc === null) {
            return Resolution::none($this->platform(), Resolution::MISSING_INPUT);
        }

        try {
            $results = $this->client->lookup(['upc' => $track->upc, 'entity' => 'song']);
        } catch (ServiceError $e) {
            return Resolution::none($this->platform(), $e->reason);
        }

        if ($track->album) {
            foreach ($results as $item) {
                if (($item['wrapperType'] ?? null) === 'collection' && is_string($url = $item['collectionViewUrl'] ?? null)) {
                    return $this->found($url);
                }
            }

            return Resolution::none($this->platform(), Resolution::NOT_FOUND);
        }

        $songs = array_values(array_filter($results, fn (array $item) => ($item['wrapperType'] ?? null) === 'track' && ($item['kind'] ?? null) === 'song'));

        if ($songs === []) {
            return Resolution::none($this->platform(), Resolution::NOT_FOUND);
        }

        if ($track->positionIsOnRelease()) {
            $candidates = array_values(array_filter($songs, fn (array $song) => (int) ($song['trackNumber'] ?? 0) === $track->trackNumber
                && ($track->discNumber === null || (int) ($song['discNumber'] ?? 1) === $track->discNumber)));
        } else {
            // No position on this very release (none known, or Deezer's is on
            // a compilation or another edition): only a one-track release is
            // unambiguous.
            $candidates = count($songs) === 1 ? $songs : [];
        }

        if ($candidates === []) {
            return Resolution::none($this->platform(), Resolution::NO_CONFIDENT_MATCH);
        }

        $song = $candidates[0];
        $tolerance = (int) config('smartlinks.services.itunes.duration_tolerance', 10);

        if ($track->duration !== null && is_numeric($song['trackTimeMillis'] ?? null)
            && abs(((int) $song['trackTimeMillis']) / 1000 - $track->duration) > $tolerance) {
            return Resolution::none($this->platform(), Resolution::MISMATCH);
        }

        return is_string($url = $song['trackViewUrl'] ?? null)
            ? $this->found($url)
            : Resolution::none($this->platform(), Resolution::NOT_FOUND);
    }

    protected function found(string $url): Resolution
    {
        $url = $this->cleaner->clean($url);

        return app(Platforms::class)->detect($url) === $this->platform()
            ? Resolution::found($this->platform(), $url)
            : Resolution::none($this->platform(), Resolution::MISMATCH);
    }
}
