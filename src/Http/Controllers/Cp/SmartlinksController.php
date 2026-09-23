<?php

namespace Goldnead\Smartlinks\Http\Controllers\Cp;

use Goldnead\Smartlinks\Smartlinks;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;
use Statamic\CP\Column;
use Statamic\Entries\Entry;
use Statamic\Facades\Entry as Entries;
use Statamic\Http\Controllers\CP\CpController;

/**
 * Smart Links: every song of the configured collections with its clicks per
 * platform over the last `smartlinks.cp.days` days.
 *
 * Client-mode listing: a band has dozens of songs, a label a few thousand;
 * past {@see self::LIMIT} the page says so.
 */
class SmartlinksController extends CpController
{
    public const LIMIT = 2000;

    public function index(Smartlinks $smartlinks): Response
    {
        Gate::authorize('view smartlinks');

        $days = max(1, (int) config('smartlinks.cp.days', 30));

        if (! Schema::hasTable(Smartlinks::TABLE)) {
            Log::warning('statamic-smartlinks: the smartlinks_clicks table is missing; run php artisan migrate.');

            return Inertia::render('smartlinks::Smartlinks/Index', [
                'rows' => [],
                'initialColumns' => [],
                'setupRequired' => true,
                'days' => $days,
            ]);
        }

        $clicks = $smartlinks->clicks($days);
        $collections = $smartlinks->collections();

        $entries = $collections === []
            ? collect()
            : Entries::query()->whereIn('collection', $collections)->limit(self::LIMIT + 1)->get();

        $truncated = $entries->count() > self::LIMIT;

        $platformTotals = [];
        foreach ($clicks as $perPlatform) {
            foreach ($perPlatform as $platform => $count) {
                $platformTotals[$platform] = ($platformTotals[$platform] ?? 0) + $count;
            }
        }
        arsort($platformTotals);

        $rows = $entries->take(self::LIMIT)->map(function (Entry $entry) use ($clicks, $platformTotals, $smartlinks) {
            $counts = $clicks[(string) $entry->id()] ?? [];
            $row = [
                'id' => (string) $entry->id(),
                'title' => (string) $entry->get('title'),
                'edit_url' => $entry->editUrl(),
                'landing_url' => $smartlinks->landingUrl($entry),
                'links' => count($smartlinks->links($entry)),
                'total' => array_sum($counts),
            ];

            foreach (array_keys($platformTotals) as $platform) {
                $row['platform_'.$platform] = $counts[$platform] ?? 0;
            }

            return $row;
        })->sortByDesc('total')->values()->all();

        return Inertia::render('smartlinks::Smartlinks/Index', [
            'rows' => $rows,
            'initialColumns' => collect($this->columns($smartlinks, array_keys($platformTotals)))->map->toArray()->all(),
            'truncated' => $truncated,
            'days' => $days,
        ]);
    }

    /**
     * @param  list<string>  $platforms  the platforms with clicks, busiest first
     * @return list<Column>
     */
    protected function columns(Smartlinks $smartlinks, array $platforms): array
    {
        $columns = [
            Column::make('title')->label(__('smartlinks::cp.col_title')),
            Column::make('total')->label(__('smartlinks::cp.col_total'))->numeric(true),
            Column::make('links')->label(__('smartlinks::cp.col_links'))->numeric(true),
        ];

        foreach ($platforms as $platform) {
            $columns[] = Column::make('platform_'.$platform)
                ->label($smartlinks->platforms()->label($platform))
                ->numeric(true);
        }

        return $columns;
    }
}
