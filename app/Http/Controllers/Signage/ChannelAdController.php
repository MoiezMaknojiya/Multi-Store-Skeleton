<?php

namespace App\Http\Controllers\Signage;

use App\Http\Controllers\Controller;
use App\Http\Requests\Signage\ChannelAdRequest;
use App\Models\ActivityLog;
use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\PlaylistItem;
use App\Services\MediaStorage;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The ads inside one channel.
 *
 * Adding, editing, reordering and removing an ad are all EDITS of the channel, so all
 * of them answer to `channel-update`; deleting the channel itself is the only thing
 * `channel-destroy` guards.
 *
 * Files are posted as FormData and a video is measured in the browser, exactly like
 * the media library and the campaigns — there is no ffmpeg on the server.
 */
class ChannelAdController extends Controller implements HasMiddleware
{
    public function __construct(private readonly MediaStorage $storage) {}

    /**
     * Every action here reaches one channel, and only a channel within reach (Channel::visibleTo): inside a
     * store, the platform's channels and other stores' are not found. Checked before anything else runs —
     * an upload is not even validated against a channel out of reach.
     *
     * @return array<int, Closure>
     */
    public static function middleware(): array
    {
        return [
            function (Request $request, Closure $next) {
                $channel = $request->route('channel');

                abort_unless($channel instanceof Channel && Channel::visibleTo($request->user())->whereKey($channel->id)->exists(), 404);

                return $next($request);
            },
        ];
    }

    /** The channel's ads, in the order they play. */
    public function index(Channel $channel): JsonResponse
    {
        return response()->json(['ads' => $this->adsPayload($channel)]);
    }

    public function store(ChannelAdRequest $request, Channel $channel): JsonResponse
    {
        $validated = $request->validated();
        $upload = $request->file('file');

        $ad = DB::transaction(function () use ($validated, $upload, $channel) {
            $file = $this->storage->storeChannelFile($upload, $channel->id, $validated);

            return ChannelAd::create([
                ...$this->fileAttributes($file, $validated),
                'channel_id' => $channel->id,
                'title' => filled($validated['title'] ?? null) ? $validated['title'] : $this->titleFromFilename($upload),
                'starts_on' => $validated['starts_on'] ?? null,
                'ends_on' => $validated['ends_on'] ?? null,
                // A new ad joins the end of the channel.
                'position' => ((int) ChannelAd::where('channel_id', $channel->id)->max('position')) + 1,
                'created_by' => auth()->id(),
            ]);
        });

        ActivityLog::record('channel.ad_added', $channel, "Added ad {$ad->title} to channel {$channel->name}");

        return response()->json(['message' => 'Ad added', 'ads' => $this->adsPayload($channel)]);
    }

    /** Retitle, re-time or re-date an ad — and optionally swap the file itself. */
    public function update(ChannelAdRequest $request, Channel $channel, ChannelAd $ad): JsonResponse
    {
        $validated = $request->validated();
        $upload = $request->file('file');

        DB::transaction(function () use ($validated, $upload, $channel, $ad) {
            $attributes = [
                'title' => filled($validated['title'] ?? null) ? $validated['title'] : $ad->title,
                'starts_on' => $validated['starts_on'] ?? null,
                'ends_on' => $validated['ends_on'] ?? null,
            ];

            if ($upload !== null) {
                $old = [$ad->disk, $ad->path, $ad->thumbnail_path];
                $attributes = [
                    ...$attributes,
                    ...$this->fileAttributes($this->storage->storeChannelFile($upload, $channel->id, $validated), $validated),
                ];
            } elseif ($ad->type === Media::TYPE_IMAGE) {
                $attributes['duration_seconds'] = (int) ($validated['seconds'] ?? $ad->duration_seconds);
            }

            $ad->update($attributes);

            // The replaced file goes only once the row is committed as pointing at the
            // new one: a stale file on disk is harmless, a row pointing at a deleted
            // file is a black rectangle on somebody's wall.
            if (isset($old)) {
                DB::afterCommit(fn () => $this->storage->deleteFiles(...$old));
            }
        });

        ActivityLog::record('channel.ad_updated', $channel, "Updated ad {$ad->title} in channel {$channel->name}");

        return response()->json(['message' => 'Ad updated', 'ads' => $this->adsPayload($channel)]);
    }

    public function destroy(Channel $channel, ChannelAd $ad): JsonResponse
    {
        $title = $ad->title;
        $files = [$ad->disk, $ad->path, $ad->thumbnail_path];

        DB::transaction(function () use ($ad, $files) {
            $ad->delete();
            DB::afterCommit(fn () => $this->storage->deleteFiles(...$files));
        });

        ActivityLog::record('channel.ad_removed', $channel, "Removed ad {$title} from channel {$channel->name}");

        return response()->json(['message' => 'Ad removed', 'ads' => $this->adsPayload($channel)]);
    }

    /**
     * Put the channel's ads in a new order.
     *
     * The whole order in one call, and exactly this channel's own ads: a partial list
     * would leave two ads sharing a place, and a foreign id would reach into somebody
     * else's channel.
     */
    public function reorder(Request $request, Channel $channel): JsonResponse
    {
        $validated = $request->validate([
            'ad_ids' => ['present', 'array'],
            'ad_ids.*' => ['integer', 'distinct'],
        ]);

        $posted = collect($validated['ad_ids'])->map(fn ($id) => (int) $id)->values();
        $current = ChannelAd::where('channel_id', $channel->id)->pluck('id')->map(fn ($id) => (int) $id);

        if ($posted->sort()->values()->all() !== $current->sort()->values()->all()) {
            throw ValidationException::withMessages([
                'ad_ids' => 'The ads in this channel have changed. Reload the page and try again.',
            ]);
        }

        DB::transaction(function () use ($posted, $channel) {
            $posted->each(fn (int $id, int $position) => ChannelAd::where('channel_id', $channel->id)
                ->whereKey($id)
                ->update(['position' => $position]));
        });

        ActivityLog::record('channel.ads_reordered', $channel, "Reordered the ads in channel {$channel->name}");

        return response()->json(['message' => 'Order saved', 'ads' => $this->adsPayload($channel)]);
    }

    /**
     * The columns an ad keeps about its file.
     *
     * An image gets the seconds it was given. A video gets none — it runs to its own
     * end, which is the length the browser measured.
     *
     * @param  array<string, mixed>  $file
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function fileAttributes(array $file, array $validated): array
    {
        $isVideo = $file['type'] === Media::TYPE_VIDEO;

        return [
            'type' => $file['type'],
            'mime_type' => $file['mime_type'],
            'disk' => $file['disk'],
            'path' => $file['path'],
            'thumbnail_path' => $file['thumbnail_path'],
            'size' => $file['size'],
            'width' => $file['width'],
            'height' => $file['height'],
            'orientation' => $file['orientation'],
            'media_duration_seconds' => $isVideo ? $file['duration_seconds'] : null,
            // The rules demand seconds for an image, so they are always here — with a
            // fallback anyway, because the alternative to a default is a 500 on an upload.
            'duration_seconds' => $isVideo ? null : (int) ($validated['seconds'] ?? PlaylistItem::DEFAULT_IMAGE_SECONDS),
        ];
    }

    /** "coke-2l-deal.jpg" becomes "coke-2l-deal", when nobody typed a title. */
    private function titleFromFilename(UploadedFile $upload): string
    {
        $name = trim(pathinfo($upload->getClientOriginalName(), PATHINFO_FILENAME));

        return $name !== '' ? Str::limit($name, 255, '') : 'Ad';
    }

    /** @return array<int, array<string, mixed>> */
    private function adsPayload(Channel $channel): array
    {
        $today = now();

        return ChannelAd::where('channel_id', $channel->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (ChannelAd $ad) => [
                'id' => $ad->id,
                'title' => $ad->title,
                'type' => $ad->type,
                'orientation' => $ad->orientation,
                'url' => $ad->url,
                'thumbnail_url' => $ad->thumbnail_url,
                'duration_seconds' => $ad->duration_seconds,
                'play_seconds' => $ad->play_seconds,
                'starts_on' => $ad->starts_on?->toDateString(),
                'ends_on' => $ad->ends_on?->toDateString(),
                // Against the server's own date: this list is read by one person at a
                // desk, not by a screen standing in some other timezone.
                'status' => $ad->statusOn($today),
            ])
            ->all();
    }
}
