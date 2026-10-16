<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A change of email approved from the address the account has now (owner, 2026-10-15 — "email badalne per email verification ho
 * jese sub karte ha"): a confirmed account's new address waits in users.pending_email as before, and the link that confirms it
 * goes to the new address only once the link sent to the CURRENT one has been opened — the moment written here. An open
 * session alone can no longer move an account to somebody else's inbox. A change already waiting when this runs is left
 * unapproved: its person approves it from the profile (Send the Link Again), so nothing waiting slips past the new step.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Harmless on a database that has it already, as every file here is (MigrationsTest).
        if (Schema::hasColumn('users', 'pending_email_approved_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('pending_email_approved_at')->nullable()->after('pending_email');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'pending_email_approved_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('pending_email_approved_at');
        });
    }
};
