<?php

namespace Goldnead\Smartlinks;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What `smartlinks:check` last found per stored link.
 */
class LinkStatus
{
    public const TABLE = 'smartlinks_link_status';

    public const OK = 'ok';

    /** Confirmed: dead on two checks in a row. */
    public const DEAD = 'dead';

    /** Dead on the last check only: shown until the next check confirms. */
    public const SUSPECT = 'suspect';

    /** Blocked, rate-limited, timed out, unresolvable: no verdict. */
    public const UNKNOWN = 'unknown';

    public const CONFIRM_AFTER = 2;

    protected ?bool $available = null;

    public function available(): bool
    {
        return $this->available ??= Schema::hasTable(self::TABLE);
    }

    /**
     * Records one check's verdict. `dead` from the checker becomes `suspect`
     * the first time and `dead` from the second dead check in a row; `ok`
     * resets the streak; `unknown` neither confirms nor resets it.
     */
    public function record(string $entryId, string $url, string $result, ?int $httpStatus): void
    {
        $hash = hash('sha256', $url);
        $streak = (int) DB::table(self::TABLE)->where('entry_id', $entryId)->where('url_hash', $hash)->value('dead_streak');

        $streak = match ($result) {
            self::OK => 0,
            self::DEAD => min($streak + 1, 255),
            default => $streak,
        };

        $status = match (true) {
            $streak >= self::CONFIRM_AFTER => self::DEAD,
            $result === self::DEAD => self::SUSPECT,
            default => $result,
        };

        DB::table(self::TABLE)->upsert(
            [[
                'entry_id' => $entryId,
                'url_hash' => $hash,
                'url' => $url,
                'status' => $status,
                'http_status' => $httpStatus,
                'dead_streak' => $streak,
                'checked_at' => Carbon::now(),
            ]],
            ['entry_id', 'url_hash'],
            ['url', 'status', 'http_status', 'dead_streak', 'checked_at'],
        );
    }

    /**
     * Keeps the entry's rows in step with its links: a URL the cleanup
     * rewrote takes its history along, rows for URLs the entry no longer
     * holds go.
     *
     * @param  array<string, string>  $renamed  old URL => new URL
     * @param  list<string>  $current  the URLs the entry holds now
     */
    public function sync(string $entryId, array $renamed, array $current): void
    {
        if (! $this->available()) {
            return;
        }

        foreach ($renamed as $old => $new) {
            $exists = DB::table(self::TABLE)->where('entry_id', $entryId)->where('url_hash', hash('sha256', $new))->exists();
            $query = DB::table(self::TABLE)->where('entry_id', $entryId)->where('url_hash', hash('sha256', $old));

            $exists ? $query->delete() : $query->update(['url' => $new, 'url_hash' => hash('sha256', $new)]);
        }

        DB::table(self::TABLE)
            ->where('entry_id', $entryId)
            ->whereNotIn('url_hash', array_map(fn (string $url) => hash('sha256', $url), $current))
            ->delete();
    }

    /**
     * The URLs of the entry the last check found dead.
     *
     * @return list<string>
     */
    public function dead(string $entryId): array
    {
        if (! $this->available()) {
            return [];
        }

        return DB::table(self::TABLE)
            ->where('entry_id', $entryId)
            ->where('status', self::DEAD)
            ->pluck('url')
            ->map(fn ($url) => (string) $url)
            ->all();
    }

    /**
     * @return array<string, int> entry id => number of dead links
     */
    public function deadCounts(): array
    {
        if (! $this->available()) {
            return [];
        }

        return DB::table(self::TABLE)
            ->where('status', self::DEAD)
            ->groupBy('entry_id')
            ->selectRaw('entry_id, COUNT(*) as n')
            ->pluck('n', 'entry_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }
}
