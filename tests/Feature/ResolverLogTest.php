<?php

use Goldnead\Smartlinks\Contracts\Resolver;
use Goldnead\Smartlinks\Resolvers\Resolution;
use Goldnead\Smartlinks\Resolvers\ResolverChain;
use Goldnead\Smartlinks\Resolvers\Track;
use Illuminate\Support\Facades\Log;

/** A resolver that fails with an exception, the way a broken API client does. */
class FailingResolverForLogTest implements Resolver
{
    public function platform(): string
    {
        return 'deezer';
    }

    public function resolve(Track $track): Resolution
    {
        throw new RuntimeException('upstream exploded');
    }
}

it('logs a failing resolver with the entry id and the exception class', function () {
    config(['smartlinks.resolvers' => [FailingResolverForLogTest::class]]);
    Log::spy();

    $results = app(ResolverChain::class)->resolve(new Track(title: 'Alles wird gut', artist: 'ANDERS'), [], 'entry-42');

    expect($results[0]->reason)->toBe(Resolution::HTTP_ERROR);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $message === 'smartlinks: resolver failed'
        && $context['entry_id'] === 'entry-42'
        && $context['exception'] === RuntimeException::class
        && $context['platform'] === 'deezer')->once();
});
