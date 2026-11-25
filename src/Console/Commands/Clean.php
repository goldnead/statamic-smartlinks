<?php

namespace Goldnead\Smartlinks\Console\Commands;

use Goldnead\Smartlinks\LinkCleaner;
use Goldnead\Smartlinks\LinkStatus;
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

    public function handle(Smartlinks $smartlinks, LinkCleaner $cleaner, LinkStatus $statuses): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $field = (string) config('smartlinks.field', 'streaming_links');
        $key = (string) config('smartlinks.url_key', 'url');
        $urls = 0;
        $songs = 0;
        $failed = 0;

        $entries = $smartlinks->collections() === []
            ? collect()
            : Entries::query()->whereIn('collection', $smartlinks->collections())->get();

        foreach ($entries as $entry) {
            /** @var Entry $entry */
            if (! $entry->has($field)) {
                continue;
            }

            $before = $entry->get($field);
            [$rows, $changed, $renamed] = $cleaner->cleanRows($before, $key);

            if ($changed === 0) {
                continue;
            }

            $title = (string) $entry->value('title');

            if (! $dryRun) {
                if (! $entry->set($field, $rows)->saveQuietly()) {
                    $failed++;
                    $this->error("{$title}: not saved, its {$changed} URL(s) stay as they were.");

                    continue;
                }

                $statuses->sync((string) $entry->id(), $renamed, $smartlinks->storedUrls($entry));
            }

            $urls += $changed;
            $songs++;
            $this->line("{$title}: {$changed} URL(s)");
        }

        $this->components->info($dryRun
            ? "{$urls} URL(s) in {$songs} song(s) would be cleaned. Dry run: nothing saved."
            : "{$urls} URL(s) in {$songs} song(s) cleaned.");
        $this->line('Checked '.$entries->count()." entries, {$songs} changed.");

        if ($failed > 0) {
            $this->components->error("{$failed} song(s) could not be saved.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
