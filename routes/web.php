<?php

use Goldnead\Smartlinks\Http\Controllers\SmartlinkController;
use Illuminate\Support\Facades\Route;

/*
 * Mounted by Statamic inside the `web` group.
 *
 * `smartlinks.routes.enabled` switches both off. Checked here, so a disabled
 * route does not exist, and again in the controller, so a route cache built
 * while it was on cannot keep it open.
 */
if (config('smartlinks.routes.enabled', true)) {
    $prefix = trim((string) config('smartlinks.routes.prefix', 'hoeren'), '/');
    $throttle = 'throttle:'.config('smartlinks.routes.throttle', '60,1');

    Route::prefix($prefix)->middleware($throttle)->group(function (): void {
        Route::get('{slug}', [SmartlinkController::class, 'show'])
            ->where('slug', '[A-Za-z0-9_-]+')
            ->name('smartlinks.show');

        Route::get('{slug}/{platform}', [SmartlinkController::class, 'go'])
            ->where('slug', '[A-Za-z0-9_-]+')
            ->where('platform', '[a-z0-9_-]+')
            ->name('smartlinks.go');
    });
}
