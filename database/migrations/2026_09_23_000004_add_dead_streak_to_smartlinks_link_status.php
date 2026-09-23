<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dead checks in a row. A link counts as dead only from the second one on:
 * one 404 during a store's maintenance window must not hide a button.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('smartlinks_link_status', function (Blueprint $table): void {
            $table->unsignedTinyInteger('dead_streak')->default(0)->after('http_status');
        });
    }

    public function down(): void
    {
        Schema::table('smartlinks_link_status', function (Blueprint $table): void {
            $table->dropColumn('dead_streak');
        });
    }
};
