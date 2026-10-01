<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A file on its way in, sent in chunks (docs/UPLOADS-SPEC.md, owner 2026-09-29): one row per open upload, its bytes in
 * storage/app/private/uploads/{id}.part until the form it was chosen for makes its row. The organization it will count
 * to is kept so its 512 MB can count the uploads still open; an organization deleted takes them with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Already there on a database the earlier migrations built.
        if (Schema::hasTable('uploads')) {
            return;
        }

        Schema::create('uploads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('purpose', 16);
            $table->string('filename');
            $table->string('file_type', 100)->nullable();
            $table->unsignedBigInteger('size');
            $table->unsignedBigInteger('received')->default(0);
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uploads');
    }
};
