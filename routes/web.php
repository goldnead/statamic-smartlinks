<?php

use Goldnead\Smartlinks\Http\Controllers\SmartlinkController;
use Illuminate\Support\Facades\Route;

/*
 * Mounted by Statamic inside the `web` group.
 *
 * Not throttled: at a concert a whole room scans the same QR code through one
 * venue IP, and every one of them must get through. Only the counting is
 * capped (smartlinks.clicks.per_minute); the pages themselves are cheap reads.
 *
 * `smartlinks.routes.enabled` switches both off. Checked here, so a disabled
 * route does not exist, and again in the controller, so a route cache built
 * while it was on cannot keep it open.
 */
if (config('smartlinks.routes.enabled', true)) {
    $prefix = trim((string) config('smartlinks.routes.prefix', 'hoeren'), '/');

    Route::prefix($prefix)->group(function (): void {
        Route::get('{slug}', [SmartlinkController::class, 'show'])
            ->where('slug', '[A-Za-z0-9_-]+')
            ->name('smartlinks.show');

        Route::get('{slug}/{platform}', [SmartlinkController::class, 'go'])
            ->where('slug', '[A-Za-z0-9_-]+')
            ->where('platform', '[a-z0-9_-]+')
            ->name('smartlinks.go');
    });
}
