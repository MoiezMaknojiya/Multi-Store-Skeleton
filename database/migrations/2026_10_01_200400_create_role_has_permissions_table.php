<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Which permissions a role holds. A role or a permission deleted takes its rows here with it. */
    public function up(): void
    {
        // Already there on a database the earlier migrations built.
        if (Schema::hasTable('role_has_permissions')) {
            return;
        }

        Schema::create('role_has_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->unique(['role_id', 'permission_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_has_permissions');
    }
};
