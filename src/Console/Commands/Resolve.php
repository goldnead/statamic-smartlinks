<?php

namespace Goldnead\Smartlinks\Console\Commands;

use Goldnead\Smartlinks\Resolvers\Resolution;
use Goldnead\Smartlinks\Resolvers\ResolverChain;
use Goldnead\Smartlinks\Smartlinks;
use Illuminate\Console\Command;
use Statamic\Entries\Entry;
use Statamic\Facades\Entry as Entries;

class Resolve extends Command
{
    protected $signature = 'smartlinks:resolve
        {entry? : An entry ID or slug; all songs of the configured collections without it}
        {--dry-run : Show what would be added, save nothing}';

    protected $description = 'Fill missing streaming links from Spotify, Deezer and YouTube; never overwrites existing links';

    public function handle(Smartlinks $smartlinks, ResolverChain $chain): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $argument = $this->argument('entry');

        if (is_string($argument) && $argument !== '') {
            $entry = $smartlinks->findEntry($argument);

            if ($entry === null) {
                $this->components->error("No song \"{$argument}\" in the collections ".implode(', ', $smartlinks->collections()).'.');

                return self::FAILURE;
            }

            $entries = collect([$entry]);
        } else {
            $entries = $smartlinks->collections() === []
                ? collect()
                : Entries::query()->whereIn('collection', $smartlinks->collections())->get();
        }

        $rows = [];
        $addedTotal = 0;

        foreach ($entries as $entry) {
            /** @var Entry $entry */
            $result = $chain->fill($entry, $dryRun);
            $addedTotal += count($result['added']);

            foreach ($result['results'] as $resolution) {
                if ($resolution->reason === ResolverChain::ALREADY_PRESENT) {
                    continue;
                }

                $rows[] = [
                    (string) $entry->get('title'),
                    $resolution->platform,
                    $resolution->successful() ? ($dryRun ? 'would add' : 'added') : $resolution->reason,
                    $resolution->url ?? '',
                ];
            }

            if ($result['enrichment'] !== Resolution::FOUND && $result['enrichment'] !== ResolverChain::ALREADY_PRESENT) {
                $rows[] = [(string) $entry->get('title'), 'spotify lookup', $result['enrichment'], ''];
            }
        }

        if ($rows !== []) {
            $this->table(['Song', 'Platform', 'Result', 'URL'], $rows);
        }

        $this->components->info(sprintf(
            '%d link(s) %s for %d song(s).%s',
            $addedTotal,
            $dryRun ? 'would be added' : 'added',
            $entries->count(),
            $dryRun ? ' Dry run: nothing saved.' : '',
        ));

        return self::SUCCESS;
    }
}
