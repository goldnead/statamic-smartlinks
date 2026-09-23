<?php

namespace Goldnead\Smartlinks\Contracts;

use Goldnead\Smartlinks\Resolvers\Resolution;
use Goldnead\Smartlinks\Resolvers\Track;

/**
 * Finds one platform's URL for a song.
 *
 * Register implementations in `smartlinks.resolvers`. A resolver never
 * throws for an expected miss (no key, no match, API down): it returns a
 * Resolution with a reason code, and the command logs it.
 */
interface Resolver
{
    /** The platform handle this resolver fills, as Platforms detects it. */
    public function platform(): string;

    public function resolve(Track $track): Resolution;
}
