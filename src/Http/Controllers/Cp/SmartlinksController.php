<?php

namespace Goldnead\Smartlinks\Http\Controllers\Cp;

use Goldnead\Smartlinks\Smartlinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;
use Statamic\CP\Column;
use Statamic\Entries\Entry;
use Statamic\Facades\Entry as Entries;
use Statamic\Http\Controllers\CP\CpController;
use Statamic\Statamic;

/**
 * Smart Links: every song of the configured collections with its clicks per
 * platform over the last `smartlinks.cp.days` days.
 *
 * Server-mode listing, like core's Entries: the page loads the columns, the
 * rows come from {@see self::listing()} with core's paginator meta, so the
 * footer reads "1–3 of 3". Search, sort and pagination happen here over the
 * computed rows (clicks are not an entry field a query could sort by).
 */
class SmartlinksController extends CpController
{
    public const LIMIT = 2000;

    /**
     * Platform columns visible by default; the rest via "Customize columns".
     * One, so a phone shows three data columns plus the row menu, as core's
     * Entries listing does (checked at 390 px: with two the menu scrolls off).
     */
    public const VISIBLE_PLATFORMS = 1;

    public function index(Smartlinks $smartlinks): Response
    {
        Gate::authorize('view smartlinks');

        $days = $this->days();

        if (! Schema::hasTable(Smartlinks::TABLE)) {
            Log::warning('statamic-smartlinks: the smartlinks_clicks table is missing; run php artisan migrate.');

            return Inertia::render('smartlinks::Smartlinks/Index', [
                'initialColumns' => [],
                'setupRequired' => true,
                'hasSongs' => false,
                'listingUrl' => cp_route('smartlinks.listing'),
                'days' => $days,
            ]);
        }

        [$platforms] = $this->platformTotals($smartlinks, $smartlinks->clicks($days));

        return Inertia::render('smartlinks::Smartlinks/Index', [
            'initialColumns' => $this->columnsArray($smartlinks, $platforms, null),
            'hasSongs' => $this->entries($smartlinks)->isNotEmpty(),
            'listingUrl' => cp_route('smartlinks.listing'),
            'days' => $days,
        ]);
    }

    /**
     * Core's listing contract: `data` plus `meta` with the columns and the
     * paginator fields (current_page, last_page, per_page, from, to, total).
     */
    public function listing(Request $request, Smartlinks $smartlinks): JsonResponse
    {
        Gate::authorize('view smartlinks');

        $clicks = Schema::hasTable(Smartlinks::TABLE) ? $smartlinks->clicks($this->days()) : [];
        [$platforms] = $this->platformTotals($smartlinks, $clicks);

        $rows = $this->entries($smartlinks)->map(function (Entry $entry) use ($clicks, $platforms, $smartlinks) {
            $counts = $clicks[(string) $entry->id()] ?? [];
            $row = [
                'id' => (string) $entry->id(),
                'title' => (string) $entry->value('title'),
                'edit_url' => $entry->editUrl(),
                'landing_url' => $smartlinks->landingUrl($entry),
                'links' => count($smartlinks->links($entry)),
                'total' => array_sum($counts),
            ];

            foreach ($platforms as $platform) {
                $row['platform_'.$platform] = $counts[$platform] ?? 0;
            }

            return $row;
        });

        $search = mb_strtolower(trim((string) $request->input('search', '')));
        if ($search !== '') {
            $rows = $rows->filter(fn (array $row) => str_contains(mb_strtolower($row['title']), $search));
        }

        $columns = $this->columnsArray($smartlinks, $platforms, $request->input('columns'));
        $sortable = array_column($columns, 'field');
        $sort = in_array($request->input('sort'), $sortable, true) ? (string) $request->input('sort') : 'total';
        $desc = $request->input('order', $sort === 'title' ? 'asc' : 'desc') === 'desc';

        $rows = $rows->sortBy(
            fn (array $row) => is_string($row[$sort] ?? null) ? mb_strtolower($row[$sort]) : ($row[$sort] ?? 0),
            SORT_REGULAR,
            $desc,
        )->values();

        // Core's ceiling for an explicit value, its default page size otherwise.
        $perPage = (int) (Statamic::cpPerPage($request->input('perPage')) ?? config('statamic.cp.pagination_size', 50));
        $page = max(1, (int) $request->input('page', 1));
        $paginator = new LengthAwarePaginator($rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'columns' => $columns,
                'activeFilterBadges' => [],
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    protected function days(): int
    {
        return max(1, (int) config('smartlinks.cp.days', 30));
    }

    /**
     * @return Collection<int, Entry>
     */
    protected function entries(Smartlinks $smartlinks)
    {
        $collections = $smartlinks->collections();

        return $collections === []
            ? collect()
            : Entries::query()->whereIn('collection', $collections)->limit(self::LIMIT)->get();
    }

    /**
     * The platforms with clicks, busiest first.
     *
     * @param  array<string, array<string, int>>  $clicks
     * @return array{0: list<string>}
     */
    protected function platformTotals(Smartlinks $smartlinks, array $clicks): array
    {
        $totals = [];
        foreach ($clicks as $perPlatform) {
            foreach ($perPlatform as $platform => $count) {
                $totals[$platform] = ($totals[$platform] ?? 0) + $count;
            }
        }
        arsort($totals);

        return [array_map('strval', array_keys($totals))];
    }

    /**
     * Few columns visible by default, as core's Entries listing does, so the
     * title and the row menu fit on a phone. `$requested` is the listing's
     * `columns` parameter (the user's customised set) when present.
     *
     * @param  list<string>  $platforms
     * @return list<array<string, mixed>>
     */
    protected function columnsArray(Smartlinks $smartlinks, array $platforms, mixed $requested): array
    {
        $columns = [
            Column::make('title')->label(__('smartlinks::cp.col_title')),
            Column::make('total')->label(__('smartlinks::cp.col_total'))->numeric(true),
            Column::make('links')->label(__('smartlinks::cp.col_links'))->numeric(true)->defaultVisibility(false)->visible(false),
        ];

        foreach ($platforms as $i => $platform) {
            $visible = $i < self::VISIBLE_PLATFORMS;
            $columns[] = Column::make('platform_'.$platform)
                ->label($smartlinks->platforms()->label($platform))
                ->numeric(true)
                ->defaultVisibility($visible)
                ->visible($visible);
        }

        if (is_string($requested) && $requested !== '') {
            $wanted = explode(',', $requested);
            foreach ($columns as $column) {
                $column->visible(in_array($column->field(), $wanted, true) || $column->field() === 'title');
            }
        }

        return array_map(fn (Column $column) => $column->toArray(), $columns);
    }
}
