<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The platform gets a media library of its own (docs/CHANNEL-CONTENT-SPEC.md, owner 2026-09-19): a row with
 * `store_id = NULL` belongs to the platform, the way `channels.store_id = NULL` already does. NULL rather than
 * 0 because this column keeps its foreign key — the one that takes a deleted shop's rows with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->foreignId('store_id')->nullable()->change();
        });
    }

    /**
     * Back to "every row belongs to a shop" — which the platform's own files cannot: they belong to no shop. While
     * any exists the column is left nullable, rather than deleting them (the platform's library would be lost) or
     * refusing (which would stop every rollback queued behind this one — the browser tests roll the whole schema
     * back after each test). `up()` finds the column as this left it, and runs again unharmed.
     */
    public function down(): void
    {
        if (DB::table('media')->whereNull('store_id')->exists()) {
            return;
        }

        Schema::table('media', function (Blueprint $table) {
            $table->foreignId('store_id')->nullable(false)->change();
        });
    }
};
