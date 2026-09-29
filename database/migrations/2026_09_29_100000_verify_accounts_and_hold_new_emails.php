<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Email verification at signup (owner's rule, 2026-09-29). Every account made before it counts as verified —
 * nobody on the live site is locked out — and an account keeps a changed address waiting until the new one is
 * confirmed (users.pending_email), so a typo can never lock anybody out either. The accounts this marks carry one
 * exact timestamp, so down() can tell them from accounts that confirmed themselves.
 */
return new class extends Migration
{
    private const BEFORE_VERIFICATION = '2026-09-29 00:00:00';

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pending_email')->nullable()->after('email');
        });

        DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => self::BEFORE_VERIFICATION]);
    }

    public function down(): void
    {
        DB::table('users')->where('email_verified_at', self::BEFORE_VERIFICATION)->update(['email_verified_at' => null]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('pending_email');
        });
    }
};
