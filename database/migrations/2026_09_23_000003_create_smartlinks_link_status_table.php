<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The last result of `smartlinks:check` per stored link: ok, dead or
 * unknown (blocked, rate-limited, timed out: not proof of death).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smartlinks_link_status', function (Blueprint $table): void {
            $table->id();
            $table->string('entry_id', 64);
            $table->char('url_hash', 64);
            $table->text('url');
            $table->string('status', 16);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->timestamp('checked_at');

            $table->unique(['entry_id', 'url_hash'], 'smartlinks_link_status_entry_url');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smartlinks_link_status');
    }
};
