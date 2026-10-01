<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Three kinds of role (docs/ORGANIZATION-SPEC.md §4): an organization role (not global, no organization) is made
     * by the super admin and offered in every organization; a custom role (an organization) is that organization's
     * own; a platform role (global) is held on the organization_id = 0 membership.
     */
    public function up(): void
    {
        // Already there on a database the earlier migrations built.
        if (Schema::hasTable('roles')) {
            return;
        }

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // What the code recognises a role by — never its name, which a person may reword: `owner` marks the
            // Owner role; the other starter roles keep keys that give no power.
            $table->string('key', 32)->nullable()->unique();
            $table->boolean('is_global')->default(false);
            // A custom role's organization. Deleting the organization takes its custom roles with it, so one can
            // never outlive it and turn into an organization role offered everywhere.
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
