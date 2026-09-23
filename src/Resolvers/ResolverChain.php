<?php

namespace Goldnead\Smartlinks\Resolvers;

use Goldnead\Smartlinks\Contracts\Resolver;
use Goldnead\Smartlinks\Contracts\SuggestsOnly;
use Goldnead\Smartlinks\LinkCleaner;
use Goldnead\Smartlinks\Smartlinks;
use Goldnead\Smartlinks\Suggestions;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Statamic\Entries\Entry;
use Throwable;

/**
 * Fills a song's (or release's) missing platform links.
 *
 * First the identity: ISRC or UPC from what the entry holds
 * ({@see Identifier}). Then each resolver whose platform is missing, on
 * that exact key. Never overwrites: a platform the entry already has a link
 * for is not asked, found links are appended as new rows. Name matches
 * ({@see SuggestsOnly}) become suggestions, never links. One service
 * failing only costs that service.
 */
class ResolverChain
{
    public const ALREADY_PRESENT = 'already_present';

    public const ALREADY_SUGGESTED = 'already_suggested';

    public function __construct(
        protected Smartlinks $smartlinks,
        protected Identifier $identifier,
        protected LinkCleaner $cleaner,
        protected Suggestions $suggestions,
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

    /**
     * What the entry holds, before any request.
     */
    public function trackFor(Entry $entry): Track
    {
        return $this->identifier->identify($entry);
    }

    /**
     * Identifies the entry and asks each resolver whose platform is
     * missing. Returns the identification's reason code, the Track and one
     * Resolution per resolver.
     *
     * @return array{enrichment: string, track: Track, results: list<Resolution>}
     */
    public function run(Entry $entry): array
    {
        $present = array_map(fn ($link) => $link->platform, $this->smartlinks->links($entry));
        $track = $this->trackFor($entry);
        $resolvers = $this->resolvers();

        $needsLookup = collect($resolvers)->contains(fn (Resolver $r) => ! in_array($r->platform(), $present, true));
        $enrichment = $needsLookup ? $this->identifier->enrich($track) : self::ALREADY_PRESENT;

        return [
            'enrichment' => $enrichment,
            'track' => $track,
            'results' => $this->resolve($track, $present, (string) $entry->id()),
        ];
    }

    /**
     * The resolvers over a Track. `$present` platforms are skipped.
     *
     * @param  list<string>  $present
     * @return list<Resolution>
     */
    public function resolve(Track $track, array $present = [], ?string $entryId = null): array
    {
        $results = [];
        $filled = [];

        foreach ($this->resolvers() as $resolver) {
            $platform = $resolver->platform();

            if (in_array($platform, $present, true) || isset($filled[$platform])) {
                $results[] = Resolution::none($platform, self::ALREADY_PRESENT);

                continue;
            }

            $suggestOnly = $resolver instanceof SuggestsOnly;

            if ($suggestOnly && $entryId !== null && $this->suggestions->hasPending($entryId, $platform)) {
                $results[] = Resolution::none($platform, self::ALREADY_SUGGESTED);

                continue;
            }

            try {
                $result = $resolver->resolve($track);
            } catch (Throwable $e) {
                Log::warning('smartlinks: resolver failed', ['platform' => $platform, 'reason' => Resolution::HTTP_ERROR, 'error' => $e->getMessage()]);
                $result = Resolution::none($platform, Resolution::HTTP_ERROR);
            }

            if ($result->successful()) {
                $url = $this->cleaner->clean((string) $result->url);
                $result = $suggestOnly
                    ? new Resolution($platform, Resolution::SUGGESTED, $url)
                    : Resolution::found($platform, $url);

                if (! $suggestOnly) {
                    $filled[$platform] = true;
                }
            }

            $results[] = $result;
        }

        return $results;
    }

    /**
     * Runs the chain, appends what it found, stores suggestions and the
     * derived ISRC/UPC. With `$dryRun` nothing is saved. Every decision is
     * logged with its reason code.
     *
     * @return array{enrichment: string, track: Track, results: list<Resolution>, added: list<Resolution>, suggested: list<Resolution>, stored: array<string, string>}
     */
    public function fill(Entry $entry, bool $dryRun = false): array
    {
        $run = $this->run($entry);
        $added = array_values(array_filter($run['results'], fn (Resolution $r) => $r->successful()));
        $suggested = array_values(array_filter($run['results'], fn (Resolution $r) => $r->reason === Resolution::SUGGESTED && $r->url !== null));

        foreach ($run['results'] as $result) {
            Log::info('smartlinks: resolve', [
                'entry' => $entry->id(),
                'platform' => $result->platform,
                'reason' => $result->reason,
                'url' => $result->url,
                'dry_run' => $dryRun,
            ]);
        }

        $stored = $this->identifiersToStore($entry, $run['track']);

        if ($dryRun) {
            return [...$run, 'added' => $added, 'suggested' => $suggested, 'stored' => $stored];
        }

        foreach ($suggested as $suggestion) {
            $this->suggestions->add((string) $entry->id(), $suggestion->platform, (string) $suggestion->url);
        }

        foreach ($stored as $field => $value) {
            $entry->set($field, $value);
        }

        // appendLinks saves the entry that holds the links, which for a
        // localisation is its origin; the identifiers sit on the entry itself.
        $this->smartlinks->appendLinks($entry, array_map(fn (Resolution $r) => ['platform' => $r->platform, 'url' => (string) $r->url], $added));

        if ($stored !== []) {
            $entry->save();
        }

        return [...$run, 'added' => $added, 'suggested' => $suggested, 'stored' => $stored];
    }

    /**
     * ISRC and UPC the chain derived, for the configured fields the entry's
     * blueprint really has and that are still empty.
     *
     * @return array<string, string>
     */
    protected function identifiersToStore(Entry $entry, Track $track): array
    {
        $stored = [];
        $blueprint = $entry->blueprint();

        foreach (['isrc_field' => $track->isrc, 'upc_field' => $track->upc] as $config => $value) {
            $field = config('smartlinks.'.$config);

            if (! is_string($field) || $field === '' || $value === null || filled($entry->value($field))) {
                continue;
            }

            if ($blueprint !== null && $blueprint->hasField($field)) {
                $stored[$field] = $value;
            }
        }

        return $stored;
    }
}
