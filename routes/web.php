<?php

use Goldnead\Smartlinks\Http\Controllers\SmartlinkController;
use Goldnead\Smartlinks\Smartlinks;
use Illuminate\Support\Facades\Route;

/*
 * Mounted by Statamic inside the `web` group.
 *
 * Not throttled: at a concert a whole room scans the same QR code through one
 * venue IP, and every one of them must get through. Only the counting is
 * capped (smartlinks.clicks.per_minute); the pages themselves are cheap reads.
 *
 * Songs sit at {prefix}/{slug}, collections with a segment (releases:
 * `release`) at {prefix}/{segment}/{slug}. The segment routes come first, so
 * `/hoeren/release/x` is never read as song "release", platform "x".
 *
 * `smartlinks.routes.enabled` switches all off. Checked here, so a disabled
 * route does not exist, and again in the controller, so a route cache built
 * while it was on cannot keep it open.
 */
if (config('smartlinks.routes.enabled', true)) {
    $prefix = trim((string) config('smartlinks.routes.prefix', 'hoeren'), '/');
    $segments = app(Smartlinks::class)->segments();

    Route::prefix($prefix)->group(function () use ($segments): void {
        if ($segments !== []) {
            $pattern = implode('|', array_map(fn (string $s) => preg_quote($s, '/'), $segments));

            Route::get('{segment}/{slug}', [SmartlinkController::class, 'showIn'])
                ->where('segment', $pattern)
                ->where('slug', '[A-Za-z0-9_-]+')
                ->name('smartlinks.segment.show');

            Route::get('{segment}/{slug}/{platform}', [SmartlinkController::class, 'goIn'])
                ->where('segment', $pattern)
                ->where('slug', '[A-Za-z0-9_-]+')
                ->where('platform', '[a-z0-9_-]+')
                ->name('smartlinks.segment.go');
        }

        Route::get('{slug}', [SmartlinkController::class, 'show'])
            ->where('slug', '[A-Za-z0-9_-]+')
            ->name('smartlinks.show');

        Route::get('{slug}/{platform}', [SmartlinkController::class, 'go'])
            ->where('slug', '[A-Za-z0-9_-]+')
            ->where('platform', '[a-z0-9_-]+')
            ->name('smartlinks.go');
    });
}
