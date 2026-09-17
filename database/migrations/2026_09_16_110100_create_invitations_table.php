<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How people join a store — or the platform team — without anybody else choosing their
     * password. A row lives only while the invitation is open: accepting, declining or
     * revoking deletes it, and the activity log keeps the history.
     */
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            // NULL invites to the platform team (a store_id = 0 membership on accepting).
            $table->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            // Only a SHA-256 of the token is stored; the token itself lives in the email link.
            $table->char('token_hash', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['store_id', 'email']);
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
