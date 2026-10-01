<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A membership: one person, one organization, one role there — where every power in the panel comes from. The
     * platform team's memberships sit on the sentinel organization 0, which has no row in `organizations`.
     */
    public function up(): void
    {
        // Already there on a database the earlier migrations built.
        if (Schema::hasTable('organization_user')) {
            return;
        }

        Schema::create('organization_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('organization_id')->default(0);   // no foreign key: 0 is the platform
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->unique(['organization_id', 'user_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_user');
    }
};
