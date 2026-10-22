<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which way a channel's screens are (owner, 2026-10-22 — "har vendor ya wholesaler ke liye do channels banaunga: ek landscape
 * orientation ke liye aur ek portrait orientation ke liye"): landscape or portrait, chosen when the channel is made and fixed
 * after, as an Ad Builder ad's is. A channel takes files of its own shape alone and is offered to screens of that shape — a
 * flipped screen counted as its shape, since the player turns it.
 *
 * A channel made before is landscape, unless every ad it holds is portrait: then it was a portrait channel all along.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Harmless on a database that has it already, as every file here is (MigrationsTest).
        if (Schema::hasColumn('channels', 'orientation')) {
            return;
        }

        Schema::table('channels', function (Blueprint $table) {
            $table->string('orientation', 20)->default('landscape')->after('name');
        });

        $portrait = DB::table('channel_ads')
            ->join('media', 'media.id', '=', 'channel_ads.media_id')
            ->groupBy('channel_ads.channel_id')
            ->havingRaw("sum(case when media.orientation = 'portrait' then 1 else 0 end) = count(*)")
            ->pluck('channel_ads.channel_id');

        DB::table('channels')->whereIn('id', $portrait)->update(['orientation' => 'portrait']);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('channels', 'orientation')) {
            return;
        }

        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn('orientation');
        });
    }
};
