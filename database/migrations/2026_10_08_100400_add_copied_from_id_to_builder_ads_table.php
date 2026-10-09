<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which Premium Template an organization's ad was made from (owner, 2026-10-08: above the organizations a template and an
 * organization's copy of it look alike, so the copy says "Copied from a Premium Template"). Use This Template writes it from now on
 * (TemplateCopier). The copies made before it are found through their published page, which `2026_10_08_100200` stamped with the
 * template's page (`media.copied_from_id`). NULL for every other ad, and again once the template is gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('builder_ads', 'copied_from_id')) {
            return;
        }

        Schema::table('builder_ads', function (Blueprint $table) {
            $table->foreignId('copied_from_id')->nullable()->after('organization_id')->constrained('builder_ads')->nullOnDelete();
        });

        if (! Schema::hasColumn('media', 'copied_from_id')) {
            return;
        }

        $copies = DB::table('builder_ads as copy')
            ->join('media as page', 'page.id', '=', 'copy.media_id')
            ->join('builder_ads as template', 'template.media_id', '=', 'page.copied_from_id')
            ->whereNotNull('copy.organization_id')
            ->whereNull('template.organization_id')
            ->get(['copy.id', 'template.id as template_id']);

        foreach ($copies as $copy) {
            DB::table('builder_ads')->where('id', $copy->id)->update(['copied_from_id' => $copy->template_id]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('builder_ads', 'copied_from_id')) {
            return;
        }

        Schema::table('builder_ads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('copied_from_id');
        });
    }
};
