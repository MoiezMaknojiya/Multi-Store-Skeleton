<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Every organization's files are its own (owner, 2026-10-07: Premium Templates, docs/AD-BUILDER-SPEC.md, the addendum of that day):
 * from now on an organization's designs use its own shelf alone, so an organization's ad that named one of the platform's files
 * gets the organization's own copy of it (`copied_from_id` saying which), its draft and its published version pointing at the
 * copies — and a published one is written again from its published version, so its page shows them. On live it finds nothing
 * (2026-10-08: the owner deleted Smart Stop's two drafts that named the platform's files); nothing on any television changes.
 *
 * `down()` points those designs back at the platform's files and deletes the copies whose platform file is still there.
 * Written with the query builder and its own small walker over the documents, so it says what it did on the day it ran.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('builder_assets', 'copied_from_id')) {
            return;
        }

        $platform = DB::table('builder_assets')->whereNull('organization_id')->get()->keyBy('id');

        if ($platform->isEmpty()) {
            return;
        }

        $republish = [];

        foreach (DB::table('builder_ads')->whereNotNull('organization_id')->orderBy('id')->get() as $ad) {
            $document = json_decode((string) $ad->document, true);
            $published = $ad->published_document !== null ? json_decode((string) $ad->published_document, true) : null;
            $named = array_values(array_intersect(
                array_unique([...$this->idsIn($document), ...$this->idsIn($published)]),
                $platform->keys()->all(),
            ));

            if ($named === []) {
                continue;
            }

            $swaps = [];

            foreach ($named as $id) {
                $swaps[$id] = $this->copyFor((int) $ad->organization_id, $platform[$id]);
            }

            // Not through the model: the design is not edited, so its clock — which says whether it is published — stays.
            DB::table('builder_ads')->where('id', $ad->id)->update([
                'document' => json_encode($this->swapped($document, $swaps)),
                'published_document' => $published !== null ? json_encode($this->swapped($published, $swaps)) : null,
            ]);

            if ($ad->published_at !== null && $ad->media_id !== null && $published !== null) {
                $republish[] = $ad->id;
            }
        }

        $this->writeAgain($republish);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('builder_assets', 'copied_from_id')) {
            return;
        }

        // Each copy whose platform file is still there, by the copy's id.
        $copies = DB::table('builder_assets as copy')
            ->join('builder_assets as source', 'source.id', '=', 'copy.copied_from_id')
            ->whereNotNull('copy.organization_id')
            ->whereNull('source.organization_id')
            ->get(['copy.id', 'copy.organization_id', 'copy.disk', 'copy.path', 'copy.thumbnail_path', 'copy.copied_from_id'])
            ->keyBy('id');

        if ($copies->isEmpty()) {
            return;
        }

        $republish = [];

        foreach (DB::table('builder_ads')->whereNotNull('organization_id')->orderBy('id')->get() as $ad) {
            $swaps = $copies->where('organization_id', $ad->organization_id)->map(fn (object $copy) => (int) $copy->copied_from_id)->all();
            $document = json_decode((string) $ad->document, true);
            $published = $ad->published_document !== null ? json_decode((string) $ad->published_document, true) : null;

            if ($swaps === [] || array_intersect(array_keys($swaps), [...$this->idsIn($document), ...$this->idsIn($published)]) === []) {
                continue;
            }

            DB::table('builder_ads')->where('id', $ad->id)->update([
                'document' => json_encode($this->swapped($document, $swaps)),
                'published_document' => $published !== null ? json_encode($this->swapped($published, $swaps)) : null,
            ]);

            if ($ad->published_at !== null && $ad->media_id !== null && $published !== null) {
                $republish[] = $ad->id;
            }
        }

        DB::table('builder_assets')->whereIn('id', $copies->keys()->all())->delete();

        foreach ($copies as $copy) {
            Storage::disk($copy->disk)->delete(array_filter([$copy->path, $copy->thumbnail_path]));
        }

        $this->writeAgain($republish);
    }

    /** The organization's copy of a platform file: the one it already holds, or a new one beside its uploads. */
    private function copyFor(int $organizationId, object $file): int
    {
        $held = DB::table('builder_assets')->where('organization_id', $organizationId)->where('copied_from_id', $file->id)->value('id');

        if ($held !== null) {
            return (int) $held;
        }

        $directory = "builder/{$organizationId}/assets";
        $name = (string) Str::ulid();
        $path = "{$directory}/{$name}.".pathinfo($file->path, PATHINFO_EXTENSION);
        $thumbnail = null;
        $disk = Storage::disk($file->disk);

        if ($disk->exists($file->path)) {
            $disk->copy($file->path, $path);
        }

        if ($file->thumbnail_path !== null && $disk->exists($file->thumbnail_path)) {
            $thumbnail = "{$directory}/thumbs/{$name}.jpg";
            $disk->copy($file->thumbnail_path, $thumbnail);
        }

        return (int) DB::table('builder_assets')->insertGetId([
            'organization_id' => $organizationId,
            'copied_from_id' => $file->id,
            'title' => $file->title,
            'kind' => $file->kind,
            'mime_type' => $file->mime_type,
            'disk' => $file->disk,
            'path' => $path,
            'thumbnail_path' => $thumbnail,
            'size' => $file->size,
            'width' => $file->width,
            'height' => $file->height,
            'duration_seconds' => $file->duration_seconds,
            'created_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return list<int> every file a design names, in its elements and its background layers */
    private function idsIn(mixed $document): array
    {
        if (! is_array($document)) {
            return [];
        }

        $holders = [
            ...(is_array($document['elements'] ?? null) ? $document['elements'] : []),
            ...(is_array($document['stage']['background']['layers'] ?? null) ? $document['stage']['background']['layers'] : []),
        ];

        return array_values(array_unique(array_map('intval', array_filter(
            array_map(fn (mixed $holder) => is_array($holder) ? ($holder['assetId'] ?? null) : null, $holders),
            fn (mixed $id) => is_int($id) || (is_string($id) && ctype_digit($id)),
        ))));
    }

    /**
     * The design with the files $swaps names swapped (old id => new id); every other file it names stays as it is.
     *
     * @param  array<int, int>  $swaps
     */
    private function swapped(mixed $document, array $swaps): mixed
    {
        if (! is_array($document)) {
            return $document;
        }

        $swap = function (mixed $holder) use ($swaps): mixed {
            $id = is_array($holder) ? ($holder['assetId'] ?? null) : null;

            if ((is_int($id) || (is_string($id) && ctype_digit($id))) && isset($swaps[(int) $id])) {
                $holder['assetId'] = $swaps[(int) $id];
            }

            return $holder;
        };

        if (is_array($document['elements'] ?? null)) {
            $document['elements'] = array_map($swap, $document['elements']);
        }

        if (is_array($document['stage']['background']['layers'] ?? null)) {
            $document['stage']['background']['layers'] = array_map($swap, $document['stage']['background']['layers']);
        }

        return $document;
    }

    /** @param  list<int>  $ids  published ads whose page must show the files they now name */
    private function writeAgain(array $ids): void
    {
        if ($ids !== []) {
            Artisan::call('builder:recompile', ['--ad' => $ids]);
        }
    }
};
