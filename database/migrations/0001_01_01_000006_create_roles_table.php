<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Global roles apply system-wide (assigned on the store_id = 0 sentinel);
            // is_signup_default marks the single role public self-registration grants.
            $table->boolean('is_global')->default(false);
            $table->boolean('is_signup_default')->default(false);
            // Per-store isolation: the store this role was created in. NULL means the
            // role is not store-scoped (global roles, and roles made by super/global
            // admins who have no store context) and may be used in any store.
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
