<?php

namespace Goldnead\Smartlinks\Console\Commands;

use Goldnead\Smartlinks\Smartlinks;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Deletes day counters older than `--days` (default
 * `smartlinks.clicks.prune_days`, 400: a year plus a margin for comparing
 * with last year's release). Schedule it, e.g. daily:
 *
 *     Schedule::command('smartlinks:prune')->daily();
 */
class Prune extends Command
{
    protected $signature = 'smartlinks:prune
        {--days= : Keep this many days, today included (default: smartlinks.clicks.prune_days)}';

    protected $description = 'Delete smart link click counters older than the given number of days';

    public function handle(): int
    {
        $option = $this->option('days');
        $days = $option === null ? (int) config('smartlinks.clicks.prune_days', 400) : (int) $option;

        if ($days < 1) {
            $this->components->error('--days must be at least 1.');

            return self::FAILURE;
        }

        $before = Carbon::now()->subDays($days - 1)->toDateString();
        $deleted = DB::table(Smartlinks::TABLE)->where('day', '<', $before)->delete();

        $this->components->info("{$deleted} counter row(s) before {$before} deleted.");

        return self::SUCCESS;
    }
}
