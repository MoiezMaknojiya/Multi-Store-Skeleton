<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The app is sold to hospitals, schools and other institutes as well as shops, so what people read says "organization"
 * where it said "store" or "shop" (owner, 2026-10-01: "store change kar k organization kar du? baki sub functionality same
 * rakho bs naam change ... hospital aur education like school aur bhi dusre institue ko bhi sell kar saku"). Only the
 * words change: the permission names (`store-view` …), the tables and the routes stay as they are.
 *
 * Four labels carry the word. A label is changed only while it still reads as this release shipped it, compared in PHP
 * (a collation would match a label the super admin retyped in other capitals), so one renamed on the Permissions page stays.
 */
return new class extends Migration
{
    /** @var array<string, array{0: string, 1: string}> name => [the old label, the new label] */
    private const LABELS = [
        'store-update' => ['Update Store Details', 'Update Organization Details'],
        'store-view' => ['View Stores', 'View Organizations'],
        'store-store' => ['Create Stores', 'Create Organizations'],
        'store-destroy' => ['Delete Stores', 'Delete Organizations'],
    ];

    public function up(): void
    {
        $this->relabel(0, 1);
    }

    public function down(): void
    {
        $this->relabel(1, 0);
    }

    private function relabel(int $from, int $to): void
    {
        DB::transaction(function () use ($from, $to) {
            $rows = DB::table('permissions')->whereIn('name', array_keys(self::LABELS))->get(['id', 'name', 'label']);

            foreach ($rows as $row) {
                $labels = self::LABELS[$row->name] ?? null;

                if ($labels !== null && $row->label === $labels[$from]) {
                    DB::table('permissions')->where('id', $row->id)->update(['label' => $labels[$to], 'updated_at' => now()]);
                }
            }
        });
    }
};
