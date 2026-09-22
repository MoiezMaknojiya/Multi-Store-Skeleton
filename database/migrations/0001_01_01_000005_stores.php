<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A store is the organization: it owns its members, custom roles, invitations, screens, media, dayparts,
     * its own channels, and its Ad Builder designs with the shelf of pictures and videos they are built from.
     * Deleting one takes all of that with it, for good (Store::purgeContents) — never the people's accounts.
     */
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('slug')->unique();
            // Address Fields
            $table->string('street');
            $table->string('suite')->nullable();
            $table->string('city');
            $table->string('state', 2);
            $table->string('zip_code', 10);
            $table->string('country');

            $table->boolean('is_active')->default(true);
            // Whether this shop carries network advertising at all. Off until the platform owner makes the deal:
            // a shop that has not been asked has not agreed. Each screen then says whether IT carries them.
            $table->boolean('accepts_network_ads')->default(false);
            // Who created the store — history only; nothing is ever deleted through it.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stores');
    }
};
