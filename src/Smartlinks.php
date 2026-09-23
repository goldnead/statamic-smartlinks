<?php

namespace Goldnead\Smartlinks;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Statamic\Entries\Entry;
use Statamic\Facades\Entry as Entries;
use Statamic\Facades\Site;

/**
 * Reads a song's streaming links, derives their platforms, builds the smart
 * link URLs and counts clicks. The songs themselves stay ordinary entries of
 * the site; nothing here writes to them except `smartlinks:resolve`.
 */
class Smartlinks
{
    public const TABLE = 'smartlinks_clicks';

    public function __construct(protected Platforms $platforms) {}

    public function platforms(): Platforms
    {
        return $this->platforms;
    }

    /**
     * @return list<string>
     */
    public function collections(): array
    {
        return array_values(array_unique(array_map('strval', [
            ...(array) config('smartlinks.collections', []),
            ...(array) config('smartlinks.release_collections', []),
        ])));
    }

    /**
     * An entry of a release collection: identified by UPC, linked as album.
     */
    public function isRelease(Entry $entry): bool
    {
        return in_array($entry->collectionHandle(), array_map('strval', (array) config('smartlinks.release_collections', [])), true);
    }

    /**
     * Appends links as new rows, never touching existing ones, and saves.
     * Writes where the links live: a localisation that inherits them gets
     * them added on its origin, not a copy that ends inheritance.
     *
     * @param  array<int, array{platform: string, url: string}>  $links
     */
    public function appendLinks(Entry $entry, array $links): void
    {
        if ($links === []) {
            return;
        }

        $field = (string) config('smartlinks.field', 'streaming_links');
        $key = (string) config('smartlinks.url_key', 'url');
        $platformKey = config('smartlinks.platform_key', 'platform');
        $asLabel = config('smartlinks.platform_value', 'handle') === 'label';

        $target = $entry;
        while (! $target->has($field) && $target->origin() instanceof Entry) {
            $target = $target->origin();
        }

        $rows = is_array($current = $target->get($field)) ? array_values($current) : [];
        $isList = $rows !== [] && collect($rows)->every(fn ($row) => is_string($row));

        foreach ($links as $link) {
            if ($isList) {
                $rows[] = $link['url'];

                continue;
            }

            $row = [$key => $link['url']];

            if (is_string($platformKey) && $platformKey !== '') {
                $row[$platformKey] = $asLabel ? $this->platforms->label($link['platform']) : $link['platform'];
            }

            $rows[] = $row;
        }

        $target->set($field, $rows)->save();
    }

    public function handles(Entry $entry): bool
    {
        return in_array($entry->collectionHandle(), $this->collections(), true);
    }

    /**
     * The entry's links, one per platform, ordered by `smartlinks.priority`,
     * then the rest alphabetically, `other` last. The first stored link of a
     * platform wins; rows that are not http(s) URLs are skipped.
     *
     * @return list<Link>
     */
    public function links(Entry $entry): array
    {
        $links = $this->storedLinks($entry);
        $rank = $this->priority();

        usort($links, function (Link $a, Link $b) use ($rank): int {
            $ra = $rank[$a->platform] ?? ($a->platform === Platforms::OTHER ? PHP_INT_MAX : PHP_INT_MAX - 1);
            $rb = $rank[$b->platform] ?? ($b->platform === Platforms::OTHER ? PHP_INT_MAX : PHP_INT_MAX - 1);

            return $ra <=> $rb ?: strcmp($a->platform, $b->platform);
        });

        return $links;
    }

    /**
     * platform => position. Handles are accepted with or without underscores
     * (`apple_music` is `applemusic`).
     *
     * @return array<string, int>
     */
    protected function priority(): array
    {
        $rank = [];

        foreach (array_values((array) config('smartlinks.priority', [])) as $i => $platform) {
            $rank[str_replace('_', '', strtolower((string) $platform))] ??= $i;
        }

        return $rank;
    }

    /**
     * @return list<Link>
     */
    protected function storedLinks(Entry $entry): array
    {
        $links = [];

        // Links the last check found dead are left out (smartlinks.check.hide_dead):
        // a second link of the platform takes over, or the resolver fills the gap.
        $dead = config('smartlinks.check.hide_dead', true)
            ? app(LinkStatus::class)->dead((string) $entry->id())
            : [];

        foreach ($this->storedUrls($entry) as $url) {
            $platform = $this->platforms->detect($url);

            if (isset($links[$platform]) || in_array($url, $dead, true)) {
                continue;
            }

            $links[$platform] = new Link(
                platform: $platform,
                url: $url,
                label: $this->platforms->label($platform),
                clickUrl: $this->clickUrl($entry, $platform),
            );
        }

        return array_values($links);
    }

    /**
     * Every http(s) URL in the configured field, in stored order.
     *
     * @return list<string>
     */
    public function storedUrls(Entry $entry): array
    {
        // value(), not get(): a localisation inherits the links of its origin.
        $rows = $entry->value((string) config('smartlinks.field', 'streaming_links'));
        $key = (string) config('smartlinks.url_key', 'url');
        $urls = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $url = is_array($row) ? ($row[$key] ?? null) : $row;

            if (is_string($url) && Platforms::isWebUrl($url)) {
                $urls[] = trim($url);
            }
        }

        return $urls;
    }

    public function url(Entry $entry, string $platform): ?string
    {
        foreach ($this->links($entry) as $link) {
            if ($link->platform === $platform) {
                return $link->url;
            }
        }

        return null;
    }

    public function routesEnabled(): bool
    {
        return (bool) config('smartlinks.routes.enabled', true);
    }

    public function landingUrl(Entry $entry): ?string
    {
        if (! $this->routesEnabled() || ! Route::has('smartlinks.show') || ! $entry->slug()) {
            return null;
        }

        return route('smartlinks.show', ['slug' => $entry->slug()]);
    }

    public function clickUrl(Entry $entry, string $platform): ?string
    {
        if (! $this->routesEnabled() || ! Route::has('smartlinks.go') || ! $entry->slug()) {
            return null;
        }

        return route('smartlinks.go', ['slug' => $entry->slug(), 'platform' => $platform]);
    }

    /**
     * A published entry of a smart link collection in the current site.
     */
    public function findBySlug(string $slug): ?Entry
    {
        $collections = $this->collections();

        if ($collections === [] || $slug === '') {
            return null;
        }

        /** @var Entry|null $entry */
        $entry = Entries::query()
            ->whereIn('collection', $collections)
            ->where('slug', $slug)
            ->where('site', Site::current()->handle())
            ->whereStatus('published')
            ->first();

        return $entry;
    }

    /**
     * An entry by ID, or by slug within the smart link collections of the
     * current site (a slug is only unique per site).
     */
    public function findEntry(string $idOrSlug): ?Entry
    {
        $entry = Entries::find($idOrSlug);

        if ($entry instanceof Entry) {
            return $this->handles($entry) ? $entry : null;
        }

        /** @var Entry|null $bySlug */
        $bySlug = Entries::query()
            ->whereIn('collection', $this->collections())
            ->where('slug', $idOrSlug)
            ->where('site', Site::current()->handle())
            ->first();

        return $bySlug;
    }

    /**
     * A browser prefetch or prerender, not a person clicking.
     */
    public function isPrefetch(Request $request): bool
    {
        foreach (['Sec-Purpose', 'Purpose', 'X-Moz', 'X-Purpose'] as $header) {
            if (str_contains(strtolower((string) $request->header($header)), 'prefetch')) {
                return true;
            }
        }

        return false;
    }

    /**
     * At most `smartlinks.clicks.per_minute` counted clicks per IP, song and
     * platform. Beyond that the listener is still redirected, the click just
     * is not counted. The IP is hashed into a cache key that expires after a
     * minute; it never reaches the database.
     */
    public function withinCountLimit(Request $request, Entry $entry, string $platform): bool
    {
        $limit = (int) config('smartlinks.clicks.per_minute', 10);

        if ($limit <= 0) {
            return true;
        }

        $key = 'smartlinks:click:'.hash('sha256', $request->ip().'|'.$entry->id().'|'.$platform);

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return false;
        }

        RateLimiter::hit($key, 60);

        return true;
    }

    public function isBot(?string $userAgent): bool
    {
        $userAgent = strtolower(trim((string) $userAgent));

        if ($userAgent === '') {
            return true;
        }

        foreach ((array) config('smartlinks.clicks.bots', []) as $needle) {
            $needle = strtolower(trim((string) $needle));

            if ($needle !== '' && str_contains($userAgent, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * One more click for this song and platform today. A unique index on
     * (entry, platform, day) and an upsert, so parallel clicks never create a
     * second row. Nothing about the listener is stored.
     */
    public function recordClick(Entry $entry, string $platform): void
    {
        DB::table(self::TABLE)->upsert(
            [[
                'entry_id' => (string) $entry->id(),
                'platform' => $platform,
                'day' => Carbon::now()->toDateString(),
                'clicks' => 1,
            ]],
            ['entry_id', 'platform', 'day'],
            ['clicks' => DB::raw('clicks + 1')],
        );
    }

    /**
     * Clicks since `$days` days ago (today included), per entry and platform.
     *
     * @return array<string, array<string, int>> entry id => platform => clicks
     */
    public function clicks(int $days): array
    {
        $since = Carbon::now()->subDays(max($days, 1) - 1)->toDateString();
        $totals = [];

        DB::table(self::TABLE)
            ->where('day', '>=', $since)
            ->groupBy('entry_id', 'platform')
            ->selectRaw('entry_id, platform, SUM(clicks) as total')
            ->get()
            ->each(function (object $row) use (&$totals): void {
                $totals[(string) $row->entry_id][(string) $row->platform] = (int) $row->total;
            });

        return $totals;
    }
}
