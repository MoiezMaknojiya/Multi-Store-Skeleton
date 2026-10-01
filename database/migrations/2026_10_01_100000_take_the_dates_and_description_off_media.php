<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A file in the library is kept to its name (owner, 2026-10-01: "media library samaj lo asset ki terha hi ha ... phir
 * playlist mein add kar k apne hisab se schedule set kar dega", "srif naam rakho"): when a file plays is said on its
 * playlist line alone, so its own start and expiry go, and so does its description. down() puts the columns back
 * empty: what they held is not kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'expires_at']);
        });

        Schema::table('media', function (Blueprint $table) {
            $table->dropColumn(['description', 'starts_at', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->text('description')->nullable()->after('title');
            $table->timestamp('starts_at')->nullable()->after('orientation');
            $table->timestamp('expires_at')->nullable()->after('starts_at');
            $table->index(['store_id', 'expires_at']);
        });
    }
};
