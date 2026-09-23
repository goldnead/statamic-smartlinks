<?php

namespace Goldnead\Smartlinks\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static list<\Goldnead\Smartlinks\Link> links(\Statamic\Entries\Entry $entry)
 * @method static string|null url(\Statamic\Entries\Entry $entry, string $platform)
 * @method static string|null landingUrl(\Statamic\Entries\Entry $entry)
 * @method static string|null clickUrl(\Statamic\Entries\Entry $entry, string $platform)
 * @method static void recordClick(\Statamic\Entries\Entry $entry, string $platform)
 * @method static array<string, array<string, int>> clicks(int $days)
 *
 * @see \Goldnead\Smartlinks\Smartlinks
 */
class Smartlinks extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Goldnead\Smartlinks\Smartlinks::class;
    }
}
