<?php

namespace Goldnead\Smartlinks\Console\Commands;

use Goldnead\Smartlinks\LinkChecker;
use Goldnead\Smartlinks\LinkStatus;
use Goldnead\Smartlinks\Smartlinks;
use Illuminate\Console\Command;
use Statamic\Entries\Entry;
use Statamic\Facades\Entry as Entries;

/**
 * Checks every stored link. Schedule it nightly:
 *
 *     Schedule::command('smartlinks:check')->dailyAt('03:30');
 */
class Check extends Command
{
    protected $signature = 'smartlinks:check
        {entry? : An entry ID or slug; all songs without it}
        {--dry-run : Report, record nothing}';

    protected $description = 'Check stored streaming links and mark dead ones (404/410 or a host that is gone)';

    public function handle(Smartlinks $smartlinks, LinkChecker $checker, LinkStatus $statuses): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $argument = $this->argument('entry');

        if (is_string($argument) && $argument !== '') {
            $entry = $smartlinks->findEntry($argument);

            if ($entry === null) {
                $this->components->error("No song \"{$argument}\".");

                return self::FAILURE;
            }

            $entries = collect([$entry]);
        } else {
            $entries = $smartlinks->collections() === []
                ? collect()
                : Entries::query()->whereIn('collection', $smartlinks->collections())->get();
        }

        $counts = [LinkStatus::OK => 0, LinkStatus::DEAD => 0, LinkStatus::UNKNOWN => 0];
        $rows = [];

        foreach ($entries as $entry) {
            /** @var Entry $entry */
            foreach (array_unique($smartlinks->storedUrls($entry)) as $url) {
                $result = $checker->check($url);
                $counts[$result['status']]++;

                if (! $dryRun) {
                    $statuses->record((string) $entry->id(), $url, $result['status'], $result['http_status']);
                }

                if ($result['status'] !== LinkStatus::OK) {
                    $rows[] = [(string) $entry->value('title'), $result['status'], $result['http_status'] ?? '-', $url];
                }
            }
        }

        if ($rows !== []) {
            $this->table(['Song', 'Status', 'HTTP', 'URL'], $rows);
        }

        $this->components->info(sprintf(
            '%d ok, %d dead, %d unknown.%s',
            $counts[LinkStatus::OK],
            $counts[LinkStatus::DEAD],
            $counts[LinkStatus::UNKNOWN],
            $dryRun ? ' Dry run: nothing recorded.' : '',
        ));

        return self::SUCCESS;
    }
}
