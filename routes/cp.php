<?php

use Goldnead\Smartlinks\Http\Controllers\Cp\SmartlinksController;
use Illuminate\Support\Facades\Route;

// The switch bites here as well as on the nav item: a hidden entry with a
// reachable URL is not a disabled screen.
if (! config('smartlinks.cp.enabled', true)) {
    return;
}

Route::middleware('can:view smartlinks')->group(function (): void {
    Route::get('smartlinks', [SmartlinksController::class, 'index'])->name('smartlinks.index');
    Route::get('smartlinks/listing', [SmartlinksController::class, 'listing'])->name('smartlinks.listing');
});
