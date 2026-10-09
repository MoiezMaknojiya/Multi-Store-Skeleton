<?php

namespace App\Services;

use App\Models\BuilderAd;
use App\Models\BuilderAsset;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Use This Template (owner, 2026-10-07; docs/AD-BUILDER-SPEC.md, the addendum of that day): an organization's own ad made from a
 * Premium Template — the platform's published ad for every organization — with its own copy of every file the template names.
 *
 * The copy depends on nothing of the platform's, which is the point: "agar subscription khatam bhi ho jati ha toh woo select
 * tempate uska ho gaya chalta rahe aur agar super admin ... woo template delete ... kare toh super admin wala delete ho jaye
 * assest aur organization wala rahe". A file the organization already holds a copy of (`copied_from_id`) is used again rather
 * than copied twice, and everything new is counted to the organization's 512 MB under its lock — without room nothing is written.
 */
class TemplateCopier
{
    public function __construct(private readonly OrganizationStorage $quota, private readonly DiskGuard $disk) {}

    /**
     * The organization's new ad, a draft of the template's published version, named $name.
     *
     * @throws ValidationException with no room for it, on the organization's wall or the server's disk
     */
    public function copy(BuilderAd $template, int $organizationId, string $name, ?int $actorId): BuilderAd
    {
        $document = $template->published_document ?? [];
        $ids = BuilderAd::assetIdsIn($document);

        // Only the platform's own files: a template names no other, and an id that is somebody else's file is left out of the
        // copy rather than handed to the organization.
        $platformFiles = BuilderAsset::whereNull('organization_id')->whereIn('id', $ids)->get()
            ->filter(fn (BuilderAsset $asset) => Storage::disk($asset->disk)->exists($asset->path))
            ->keyBy('id');

        $poster = $template->media?->thumbnail_path;
        $poster = $poster !== null && Storage::disk('public')->exists($poster) ? $poster : null;

        // Asked of the server's reserve once, for the most it might write; the organization's wall is asked under its lock below.
        $this->disk->assertRoomFor($this->bytesOf($platformFiles) + ($poster !== null ? (int) Storage::disk('public')->size($poster) : 0), 'template');

        $written = [];

        try {
            // Under the organization's lock (withRoom with nothing to ask of it yet): what the organization already holds, and so
            // what it needs, is read INSIDE it, so two uses at the same moment can neither both fit into the last megabytes nor
            // copy one file twice.
            $ad = $this->quota->withRoom($organizationId, 0,
                function () use ($template, $organizationId, $name, $actorId, $document, $platformFiles, &$written) {
                    $held = $this->copiesHeldBy($organizationId, $platformFiles->keys()->all());
                    $needed = $this->bytesOf($platformFiles->reject(fn (BuilderAsset $file) => $held->has($file->id)));
                    $used = $this->quota->used($organizationId);

                    if ($needed > 0 && $used + $needed > OrganizationStorage::LIMIT_BYTES) {
                        throw ValidationException::withMessages(['template' => $this->quota->fullMessage($organizationId, $needed, $used)]);
                    }

                    $swaps = [];

                    foreach ($platformFiles as $id => $file) {
                        $swaps[$id] = ($held->get($id) ?? $this->copyFile($file, $organizationId, $actorId, $written))->id;
                    }

                    return BuilderAd::create([
                        'organization_id' => $organizationId,
                        // So the copy says where it came from: above the organizations it looks like its template.
                        'copied_from_id' => $template->id,
                        'name' => $name,
                        'orientation' => $template->orientation,
                        'document' => BuilderAd::withAssetsSwapped($document, $swaps),
                        'created_by' => $actorId,
                        'updated_by' => $actorId,
                    ]);
                }, 'template');
        } catch (Throwable $refused) {
            // The rows went with the transaction; the files it wrote go too.
            foreach ($written as [$disk, $path]) {
                Storage::disk($disk)->delete($path);
            }

            throw $refused;
        }

        // The poster is the design's picture, so the copy starts with it — a nicety: with no room it is simply not written.
        if ($poster !== null) {
            $copy = $ad->storageDirectory().'/poster.jpg';

            $this->quota->withRoomOrSkip($organizationId, (int) Storage::disk('public')->size($poster), function () use ($poster, $copy, $ad) {
                Storage::disk('public')->copy($poster, $copy);
                $ad->update(['thumbnail_path' => $copy]);

                return true;
            });
        }

        return $ad;
    }

    /**
     * The organization's own copy of $file, beside its uploads (`builder/{organization}/assets/…`), its preview with it.
     *
     * @param  list<array{0: string, 1: string}>  $written  every file put on disk, as [disk, path], to take away on a refusal
     */
    private function copyFile(BuilderAsset $file, int $organizationId, ?int $actorId, array &$written): BuilderAsset
    {
        $directory = "builder/{$organizationId}/assets";
        $name = (string) Str::ulid();
        $path = "{$directory}/{$name}.".pathinfo($file->path, PATHINFO_EXTENSION);
        $thumbnail = null;

        Storage::disk($file->disk)->copy($file->path, $path);
        $written[] = [$file->disk, $path];

        if ($file->thumbnail_path !== null && Storage::disk($file->disk)->exists($file->thumbnail_path)) {
            $thumbnail = "{$directory}/thumbs/{$name}.jpg";
            Storage::disk($file->disk)->copy($file->thumbnail_path, $thumbnail);
            $written[] = [$file->disk, $thumbnail];
        }

        return BuilderAsset::create([
            ...$file->only(['title', 'kind', 'mime_type', 'disk', 'size', 'width', 'height', 'duration_seconds']),
            'organization_id' => $organizationId,
            'copied_from_id' => $file->id,
            'path' => $path,
            'thumbnail_path' => $thumbnail,
            'created_by' => $actorId,
        ]);
    }

    /**
     * The organization's copies of these platform files, by the file each came from — only those whose file is still there.
     *
     * @param  list<int>  $platformIds
     * @return Collection<int, BuilderAsset>
     */
    private function copiesHeldBy(int $organizationId, array $platformIds): Collection
    {
        return BuilderAsset::where('organization_id', $organizationId)->whereIn('copied_from_id', $platformIds)->oldest('id')->get()
            ->filter(fn (BuilderAsset $copy) => Storage::disk($copy->disk)->exists($copy->path))
            ->unique('copied_from_id')
            ->keyBy('copied_from_id');
    }

    /**
     * What these files take on disk with their previews, as the organization's wall counts them.
     *
     * @param  Collection<int, BuilderAsset>  $files
     */
    private function bytesOf(Collection $files): int
    {
        return (int) $files->sum(fn (BuilderAsset $file) => $file->size
            + ($file->thumbnail_path !== null && Storage::disk($file->disk)->exists($file->thumbnail_path) ? (int) Storage::disk($file->disk)->size($file->thumbnail_path) : 0));
    }
}
