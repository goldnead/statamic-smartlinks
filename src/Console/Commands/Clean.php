<?php

namespace Goldnead\Smartlinks\Console\Commands;

use Goldnead\Smartlinks\LinkCleaner;
use Goldnead\Smartlinks\Smartlinks;
use Illuminate\Console\Command;
use Statamic\Entries\Entry;
use Statamic\Facades\Entry as Entries;

/**
 * Applies the link cleanup (LinkCleaner) to songs saved before it existed.
 */
class Clean extends Command
{
    protected $signature = 'smartlinks:clean
        {--dry-run : Show what would change, save nothing}';

    protected $description = 'Strip affiliate and tracking parameters from stored streaming links and normalise their form';

    public function handle(Smartlinks $smartlinks, LinkCleaner $cleaner): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $field = (string) config('smartlinks.field', 'streaming_links');
        $key = (string) config('smartlinks.url_key', 'url');
        $urls = 0;
        $songs = 0;

        $entries = $smartlinks->collections() === []
            ? collect()
            : Entries::query()->whereIn('collection', $smartlinks->collections())->get();

        foreach ($entries as $entry) {
            /** @var Entry $entry */
            if (! $entry->has($field)) {
                continue;
            }

            $before = $entry->get($field);
            [$rows, $changed] = $cleaner->cleanRows($before, $key);

            if ($changed === 0) {
                continue;
            }

            $urls += $changed;
            $songs++;

            if ($this->output->isVerbose() || $dryRun) {
                $this->line((string) $entry->value('title').": {$changed} URL(s)");
            }

            if (! $dryRun) {
                $entry->set($field, $rows)->saveQuietly();
            }
        }

        $this->components->info($dryRun
            ? "{$urls} URL(s) in {$songs} song(s) would be cleaned. Dry run: nothing saved."
            : "{$urls} URL(s) in {$songs} song(s) cleaned.");

        return self::SUCCESS;
    }
}
