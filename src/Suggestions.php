<?php

namespace Goldnead\Smartlinks;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Name matches waiting for review. Never linked until accepted.
 */
class Suggestions
{
    public const TABLE = 'smartlinks_suggestions';

    public const PENDING = 'pending';

    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    /**
     * Stores a suggestion unless this URL was suggested for the entry
     * before (pending, accepted or rejected). Returns whether it is new.
     */
    public function add(string $entryId, string $platform, string $url): bool
    {
        $now = Carbon::now();

        return DB::table(self::TABLE)->insertOrIgnore([
            'entry_id' => $entryId,
            'platform' => $platform,
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'status' => self::PENDING,
            'created_at' => $now,
            'updated_at' => $now,
        ]) > 0;
    }

    public function hasPending(string $entryId, string $platform): bool
    {
        return DB::table(self::TABLE)
            ->where('entry_id', $entryId)
            ->where('platform', $platform)
            ->where('status', self::PENDING)
            ->exists();
    }

    /**
     * @return list<object{id: int, entry_id: string, platform: string, url: string, status: string}>
     */
    public function pending(?string $entryId = null): array
    {
        return DB::table(self::TABLE)
            ->where('status', self::PENDING)
            ->when($entryId !== null, fn ($q) => $q->where('entry_id', $entryId))
            ->orderBy('id')
            ->get()
            ->all();
    }

    public function find(int $id): ?object
    {
        return DB::table(self::TABLE)->where('id', $id)->first();
    }

    public function mark(int $id, string $status): void
    {
        DB::table(self::TABLE)->where('id', $id)->update(['status' => $status, 'updated_at' => Carbon::now()]);
    }
}
