<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links found by name (YouTube), waiting for a person: pending, accepted or
 * rejected. A rejected URL is not suggested again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smartlinks_suggestions', function (Blueprint $table): void {
            $table->id();
            $table->string('entry_id', 64);
            $table->string('platform', 32);
            $table->text('url');
            $table->char('url_hash', 64);
            $table->string('status', 16)->default('pending');
            $table->timestamps();

            $table->unique(['entry_id', 'url_hash'], 'smartlinks_suggestions_entry_url');
            $table->index(['entry_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smartlinks_suggestions');
    }
};
