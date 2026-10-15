<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Storage given beside the 512 MB every organization has (owner, 2026-10-15 — "kisi ko extra 1 gb dena ho toh uska 512mb full ho
 * jaye toh kese bharao?", then: a whole number of GB, free for now and only shown; docs/BILLING-SPEC.md §8). The platform sets it
 * in the Billing dialog; every organization starts with none.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Harmless on a database that has it already, as every file here is (MigrationsTest).
        if (Schema::hasColumn('organizations', 'extra_storage_gb')) {
            return;
        }

        Schema::table('organizations', function (Blueprint $table) {
            $table->unsignedSmallInteger('extra_storage_gb')->default(0)->after('platform_channels_unlocked');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('organizations', 'extra_storage_gb')) {
            return;
        }

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('extra_storage_gb');
        });
    }
};
