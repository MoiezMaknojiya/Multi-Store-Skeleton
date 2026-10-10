<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The TV app's versions (owner, 2026-10-10 — "mein new apk apne super admin mein upload karu waha super admin mein developer
 * ka tab ho"): one row per APK the super admin uploads on Developer, its file on the public disk under player-app/. The newest
 * version code is the one every TV is offered; a version code is never used twice, because Android installs nothing older
 * than what a TV has.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('player_app_releases')) {
            return;
        }

        Schema::create('player_app_releases', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('version_code')->unique();
            $table->string('version_name', 32);
            // Whether the version was read from the APK itself, or typed because it could not be.
            $table->boolean('version_read_from_file')->default(true);
            $table->string('path');
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->string('notes', 500)->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_app_releases');
    }
};
