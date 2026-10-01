<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An organization is the tenant: it owns its members, custom roles, invitations, screens, media, dayparts, its own
     * channels, and its Ad Builder designs with the shelf of pictures and videos they are built from. Deleting one
     * takes all of that with it, for good (Organization::purgeContents) — never the people's accounts.
     */
    public function up(): void
    {
        // Already there on a database the earlier migrations built.
        if (Schema::hasTable('organizations')) {
            return;
        }

        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('slug')->unique();
            // Address
            $table->string('street');
            $table->string('suite')->nullable();
            $table->string('city');
            $table->string('state', 2);
            $table->string('zip_code', 10);
            $table->string('country');

            // Off pauses the organization: its own people cannot open it, and its screens keep playing.
            $table->boolean('is_active')->default(true);
            // Whether this organization carries network advertising at all. Off until the platform owner makes the
            // deal: one that has not been asked has not agreed. Each screen then says whether IT carries them.
            $table->boolean('accepts_network_ads')->default(false);
            // Who created it — history only; nothing is ever deleted through it.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
