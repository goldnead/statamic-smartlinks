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

    public const DEAD = 'dead';

    /** Blocked, rate-limited, timed out: no verdict. */
    public const UNKNOWN = 'unknown';

    protected ?bool $available = null;

    public function available(): bool
    {
        return $this->available ??= Schema::hasTable(self::TABLE);
    }

    public function record(string $entryId, string $url, string $status, ?int $httpStatus): void
    {
        DB::table(self::TABLE)->upsert(
            [[
                'entry_id' => $entryId,
                'url_hash' => hash('sha256', $url),
                'url' => $url,
                'status' => $status,
                'http_status' => $httpStatus,
                'checked_at' => Carbon::now(),
            ]],
            ['entry_id', 'url_hash'],
            ['url', 'status', 'http_status', 'checked_at'],
        );
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
