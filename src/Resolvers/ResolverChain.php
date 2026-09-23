<?php

namespace Goldnead\Smartlinks\Resolvers;

use Goldnead\Smartlinks\Contracts\Resolver;
use Goldnead\Smartlinks\Smartlinks;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Statamic\Entries\Entry;

/**
 * Fills a song's missing platform links from the configured resolvers.
 *
 * Never overwrites: a platform the entry already has a link for is not even
 * asked for, and found links are appended as new rows.
 */
class ResolverChain
{
    public const ALREADY_PRESENT = 'already_present';

    public function __construct(
        protected Smartlinks $smartlinks,
        protected SpotifyClient $spotify,
    ) {}

    /**
     * @return list<Resolver>
     */
    public function resolvers(): array
    {
        return array_values(array_map(function ($class): Resolver {
            $resolver = app($class);

            if (! $resolver instanceof Resolver) {
                throw new InvalidArgumentException("smartlinks.resolvers: {$class} does not implement ".Resolver::class);
            }

            return $resolver;
        }, (array) config('smartlinks.resolvers', [])));
    }

    public function trackFor(Entry $entry): Track
    {
        $spotifyField = config('smartlinks.spotify_field');
        $spotifyId = $spotifyField ? Track::spotifyIdFrom(is_string($value = $entry->get($spotifyField)) ? $value : null) : null;

        if ($spotifyId === null) {
            $spotifyId = $this->smartlinks->url($entry, 'spotify');
            $spotifyId = Track::spotifyIdFrom($spotifyId);
        }

        $isrcField = config('smartlinks.isrc_field');
        $isrc = $isrcField && is_string($value = $entry->get($isrcField)) ? $value : null;

        return new Track(spotifyId: $spotifyId, isrc: $isrc, title: null, artist: null);
    }

    /**
     * Asks each resolver whose platform is missing. Returns the Spotify
     * enrichment's reason code and one Resolution per resolver.
     *
     * @return array{enrichment: string, results: list<Resolution>}
     */
    public function run(Entry $entry): array
    {
        $present = array_map(fn ($link) => $link->platform, $this->smartlinks->links($entry));
        $track = $this->trackFor($entry);
        $resolvers = $this->resolvers();

        $needsLookup = collect($resolvers)->contains(fn (Resolver $r) => ! in_array($r->platform(), $present, true) && $r->platform() !== 'spotify');
        $enrichment = $needsLookup ? $this->spotify->enrich($track) : self::ALREADY_PRESENT;

        $results = [];
        $filled = [];

        foreach ($resolvers as $resolver) {
            $platform = $resolver->platform();

            if (in_array($platform, $present, true) || isset($filled[$platform])) {
                $results[] = Resolution::none($platform, self::ALREADY_PRESENT);

                continue;
            }

            $result = $resolver->resolve($track);
            $results[] = $result;

            if ($result->successful()) {
                $filled[$platform] = true;
            }
        }

        return ['enrichment' => $enrichment, 'results' => $results];
    }

    /**
     * Runs the chain and appends what it found. With `$dryRun` nothing is
     * saved. Every decision is logged with its reason code.
     *
     * @return array{enrichment: string, results: list<Resolution>, added: list<Resolution>}
     */
    public function fill(Entry $entry, bool $dryRun = false): array
    {
        $run = $this->run($entry);
        $added = array_values(array_filter($run['results'], fn (Resolution $r) => $r->successful()));

        foreach ($run['results'] as $result) {
            Log::info('smartlinks: resolve', [
                'entry' => $entry->id(),
                'platform' => $result->platform,
                'reason' => $result->reason,
                'url' => $result->url,
                'dry_run' => $dryRun,
            ]);
        }

        if ($added !== [] && ! $dryRun) {
            $field = (string) config('smartlinks.field', 'streaming_links');
            $key = (string) config('smartlinks.url_key', 'url');
            $rows = is_array($current = $entry->get($field)) ? array_values($current) : [];
            $isList = $rows !== [] && collect($rows)->every(fn ($row) => is_string($row));

            foreach ($added as $result) {
                $rows[] = $isList ? $result->url : [$key => $result->url];
            }

            $entry->set($field, $rows)->save();
        }

        return [...$run, 'added' => $added];
    }
}
