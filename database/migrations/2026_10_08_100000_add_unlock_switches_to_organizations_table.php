<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an organization has unlocked (owner, 2026-10-07 and 2026-10-08; docs/BILLING-SPEC.md §2): Premium Templates and Platform
 * Channels, $10 each. Until billing starts the platform switches them on its Organizations page — and everything stays open for
 * now ("abhi sub k liya premium khula rakho"), a new organization too, so both start true.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Harmless on a database that has them already, as every file here is (MigrationsTest).
        if (Schema::hasColumn('organizations', 'premium_templates_unlocked')) {
            return;
        }

        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('premium_templates_unlocked')->default(true)->after('is_active');
            $table->boolean('platform_channels_unlocked')->default(true)->after('premium_templates_unlocked');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('organizations', 'premium_templates_unlocked')) {
            return;
        }

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['premium_templates_unlocked', 'platform_channels_unlocked']);
        });
    }
};
