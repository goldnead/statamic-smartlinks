<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One counter per song, platform and day. No IP, no user agent, no cookie:
 * nothing in here identifies a listener.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smartlinks_clicks', function (Blueprint $table): void {
            $table->id();
            $table->string('entry_id', 64);
            $table->string('platform', 32);
            $table->date('day');
            $table->unsignedInteger('clicks')->default(0);

            // The upsert in Smartlinks::recordClick() relies on it.
            $table->unique(['entry_id', 'platform', 'day'], 'smartlinks_clicks_entry_platform_day');
            $table->index('day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smartlinks_clicks');
    }
};
