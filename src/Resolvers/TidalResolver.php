<?php

namespace Goldnead\Smartlinks\Resolvers;

use Goldnead\Smartlinks\Contracts\Resolver;
use Goldnead\Smartlinks\LinkCleaner;
use Goldnead\Smartlinks\Platforms;

/**
 * Tidal by `tracks?filter[isrc]=` or `albums?filter[barcodeId]=`, in the
 * `smartlinks.country` catalogue. Several tracks may share an ISRC; the
 * first whose ISRC really matches wins. Prefers the API's own
 * `TIDAL_SHARING` link.
 */
class TidalResolver implements Resolver
{
    public function __construct(protected TidalClient $client, protected LinkCleaner $cleaner) {}

    public function platform(): string
    {
        return 'tidal';
    }

    public function resolve(Track $track): Resolution
    {
        $key = $track->album ? $track->upc : $track->isrc;

        if ($key === null) {
            return Resolution::none($this->platform(), Resolution::MISSING_INPUT);
        }

        if (! $this->client->configured()) {
            return Resolution::none($this->platform(), Resolution::NOT_CONFIGURED);
        }

        try {
            $items = $track->album
                ? $this->client->filter('albums', ['barcodeId' => $key])
                : $this->client->filter('tracks', ['isrc' => $key]);
        } catch (ServiceError $e) {
            return Resolution::none($this->platform(), $e->reason);
        }

        if ($items === []) {
            return Resolution::none($this->platform(), Resolution::NOT_FOUND);
        }

        $attribute = $track->album ? 'attributes.barcodeId' : 'attributes.isrc';
        $match = null;

        foreach ($items as $item) {
            $value = data_get($item, $attribute);
            $normalised = $track->album ? Track::normaliseUpc(is_scalar($value) ? (string) $value : null) : Track::normaliseIsrc(is_scalar($value) ? (string) $value : null);

            if ($value === null || $normalised === $key) {
                $match = $item;
                break;
            }
        }

        if ($match === null) {
            return Resolution::none($this->platform(), Resolution::MISMATCH);
        }

        $kind = $track->album ? 'album' : 'track';
        $url = TidalClient::linkOf($match, $kind);

        if ($url === null || app(Platforms::class)->detect($url) !== 'tidal') {
            $id = data_get($match, 'id');
            $url = is_scalar($id) && preg_match('/^\d+$/', (string) $id) === 1 ? "https://tidal.com/{$kind}/{$id}" : null;
        }

        return $url !== null
            ? Resolution::found($this->platform(), $this->cleaner->clean($url))
            : Resolution::none($this->platform(), Resolution::NOT_FOUND);
    }
}
