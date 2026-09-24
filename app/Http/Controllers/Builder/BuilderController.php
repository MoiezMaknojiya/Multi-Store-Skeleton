<?php

namespace App\Http\Controllers\Builder;

use App\Http\Controllers\Concerns\ConfirmsPassword;
use App\Http\Controllers\Concerns\HandlesCrudData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Builder\BuilderAdRequest;
use App\Models\ActivityLog;
use App\Models\BuilderAd;
use App\Models\BuilderAsset;
use App\Models\PlaylistItem;
use App\Models\Store;
use App\Services\AdCompiler;
use App\Services\AdPublisher;
use App\Services\MediaStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The Ad Builder (docs/AD-BUILDER-SPEC.md): three tabs — Create, Ads and Assets — around one editor that
 * draws an advert the shape of a television: 1920×1080, or 1080×1920 for one mounted upright (§12).
 *
 * An ad belongs to a store, like everything else a shop makes, and the platform works above them all:
 * `BuilderAd::visibleTo` decides which, so a store's person never sees another shop's design and a super
 * admin sees every one with the shop's name beside it.
 */
class BuilderController extends Controller
{
    use ConfirmsPassword, HandlesCrudData;

    public function __construct(private readonly MediaStorage $storage) {}

    /** The Ads tab: everything this person may open — and, above the stores, a filter by shop. */
    public function index(): View
    {
        return view('builder.index', ['stores' => $this->storesToFilterBy()]);
    }

    /** The saved ads, newest first. */
    public function data(Request $request): JsonResponse
    {
        $filters = $request->validate(['store_id' => ['nullable', 'integer', 'min:1']]);

        // The filter only ever NARROWS what visibleTo allows: inside a store, asking for another shop's
        // ads finds none, because the store's own wall is already on the query.
        $query = BuilderAd::visibleTo(auth()->user())
            ->when($filters['store_id'] ?? null, fn (Builder $query, int|string $storeId) => $query->where('store_id', $storeId))
            ->with(['store:id,name', 'updater:id,first_name,last_name'])
            ->latest('updated_at');

        return $this->paginatedResponse(
            $request,
            $query,
            ['name'],
            'ads',
            ['*'],
            // Each row says whether a television shows it — and whether it shows the latest changes — and who
            // touched it last.
            fn (Collection $rows) => $rows->each(function (BuilderAd $ad) {
                $ad->setAttribute('is_published', $ad->isPublished());
                $ad->setAttribute('status', $ad->status());
                $ad->setAttribute('store_name', $ad->store?->name);
                $ad->setAttribute('updated_by_name', $ad->updater?->name);
                // The listing shows a poster and a name — never the whole design, draft or published.
                $ad->makeHidden(['document', 'published_document']);
            }),
        );
    }

    /**
     * The Create tab: the editor with an empty stage of the shape the chooser asked for — portrait, or
     * landscape for anything else, the way every ad was before (a page is forgiving; the save is not).
     * Nothing is written until the first save. The platform team says which shop a new ad is for, so they
     * are handed the shops to choose from.
     */
    public function create(Request $request): View
    {
        $orientation = $request->query('orientation');

        // Which way the screen is mounted comes first (docs/AD-BUILDER-SPEC.md §12): chosen once, fixed after.
        // The Create tab lands here with no answer yet — and a word nobody offers, or a list, is no answer.
        if (! in_array($orientation, [BuilderAd::LANDSCAPE, BuilderAd::PORTRAIT], true)) {
            return view('builder.choose');
        }

        return view('builder.editor', [
            'ad' => null,
            'orientation' => $orientation,
            'document' => BuilderAd::blankDocument($orientation),
            'assets' => $this->assetsForEditor(),
            'stores' => $this->storesToFilterBy(),
        ]);
    }

    /** The editor, opened on a saved ad. */
    public function edit(BuilderAd $ad): View
    {
        // Route middleware is not enough: the target has to be inside the store the actor is working in,
        // or it does not exist for them (404, never 403).
        $ad = BuilderAd::visibleTo(auth()->user())->findOrFail($ad->id);

        return view('builder.editor', [
            'ad' => $ad,
            'orientation' => $ad->orientation,
            'document' => $ad->document,
            'assets' => $this->assetsForEditor($ad->store_id),
        ]);
    }

    /** Save a new ad. */
    public function store(BuilderAdRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $storeId = $this->targetStoreId($validated);

        $ad = BuilderAd::create([
            'store_id' => $storeId,
            'name' => $validated['name'],
            // Said once, here; from now on the column decides what size a save may be (§12).
            'orientation' => $request->orientation(),
            'document' => $validated['document'],
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        $this->savePoster($ad, $request->input('thumbnail'));

        ActivityLog::record('ad.created', $ad, "Created {$ad->orientation} ad {$ad->name}");

        return response()->json([
            'message' => 'Ad saved',
            'ad' => $this->summary($ad),
        ]);
    }

    /**
     * Save an open ad — its draft. The whole document is replaced, the way a playlist is.
     *
     * A published ad that is changed stays on the screens as it was published (owner, 2026-09-21: the
     * industry's draft/publish model — Xibo, Contentful, Strapi): nobody sees the changes until they are
     * published, and Discard changes goes back to what the screens show. A save that changes nothing changes
     * nothing — not the clock, not the history (BuilderAd::wouldChangeWith).
     */
    public function update(BuilderAdRequest $request, BuilderAd $ad): JsonResponse
    {
        $ad = BuilderAd::visibleTo(auth()->user())->findOrFail($ad->id);
        $validated = $request->validated();
        $changed = $ad->wouldChangeWith($validated['name'], $validated['document']);

        if ($changed) {
            $ad->forceFill([
                'name' => $validated['name'],
                'document' => $validated['document'],
                'updated_by' => auth()->id(),
            ])->save();

            ActivityLog::record('ad.updated', $ad, "Updated ad {$ad->name}");
        }

        $this->savePoster($ad, $request->input('thumbnail'));

        return response()->json([
            'message' => $changed && $ad->isPublished()
                ? 'Changes saved — the screens keep the published version until you publish them'
                : 'Ad saved',
            'ad' => $this->summary($ad->fresh()),
        ]);
    }

    /** A copy to work from, with its own name. The copy is a draft even if the original was published. */
    public function duplicate(BuilderAd $ad): JsonResponse
    {
        $ad = BuilderAd::visibleTo(auth()->user())->findOrFail($ad->id);

        $copy = BuilderAd::create([
            'store_id' => $ad->store_id,
            'name' => $this->copyName($ad),
            'orientation' => $ad->orientation,
            'document' => $ad->document,
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        // The poster is the design's picture, so the copy starts with it — as a file of its own. Sharing the
        // original's file would let deleting the original take the copy's picture with it.
        if ($ad->thumbnail_path && Storage::disk('public')->exists($ad->thumbnail_path)) {
            $poster = $copy->storageDirectory().'/poster.jpg';

            Storage::disk('public')->copy($ad->thumbnail_path, $poster);
            $copy->update(['thumbnail_path' => $poster]);
        }

        ActivityLog::record('ad.duplicated', $copy, "Duplicated ad {$ad->name} as {$copy->name}");

        return response()->json([
            'message' => 'Ad duplicated',
            'ad' => $this->summary($copy),
        ]);
    }

    /**
     * The page the compiler would publish, from the SAVED design, shown full screen in a tab of its own —
     * the way a television would show it, before anything reaches one. Nothing is written.
     *
     * It is served with `Content-Security-Policy: sandbox allow-scripts`, so it runs in an opaque origin, as
     * the page does on a television no service worker keeps (a set whose worker keeps it plays the page
     * same-origin with the player, behind the page's own policy — docs §15): even a page that somehow carried something it
     * should not could reach nothing of this app — not its cookies, not its session, not its forms.
     */
    public function preview(BuilderAd $ad, AdCompiler $compiler): Response
    {
        // Whoever may look at the ads, and whoever may change this one: previewing is part of designing.
        abort_unless(auth()->user()->canAny(['ad-view', 'ad-update']), 403);

        $ad = BuilderAd::visibleTo(auth()->user())->findOrFail($ad->id);

        return response($compiler->compile($ad), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Security-Policy' => 'sandbox allow-scripts',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * Publish: compile the design into a page and put it in the library, where a playlist can reach it
     * (AdPublisher says how, and why the media row keeps its id).
     */
    public function publish(BuilderAd $ad, AdPublisher $publisher): JsonResponse
    {
        $ad = BuilderAd::visibleTo(auth()->user())->findOrFail($ad->id);

        $media = $publisher->publish($ad, auth()->id());

        ActivityLog::record('ad.published', $ad, "Published ad {$ad->name}");

        $screens = $publisher->screensShowing($media);

        return response()->json([
            'message' => $screens > 0
                ? "Published — {$screens} ".($screens === 1 ? 'screen is' : 'screens are').' now showing the new version'
                : 'Published to your media library, ready for a playlist',
            'ad' => $this->summary($ad->fresh()),
            'media_id' => $media->id,
        ]);
    }

    /**
     * Unpublish: take the ad off every screen, channel, picker and library until it is published again. Not a
     * delete — every playlist line and channel ad keeps its place (AdPublisher::unpublish), so it is one
     * confirmation away, like the everyday deletes, not a password.
     */
    public function unpublish(BuilderAd $ad, AdPublisher $publisher): JsonResponse
    {
        $ad = BuilderAd::visibleTo(auth()->user())->findOrFail($ad->id);

        if (! $ad->isPublished()) {
            throw ValidationException::withMessages(['ad' => 'This ad is not on any screen.']);
        }

        $reach = $this->reachInWords($publisher->screensShowing($ad->media), $publisher->channelsShowing($ad->media));

        $publisher->unpublish($ad);

        ActivityLog::record('ad.unpublished', $ad, "Unpublished ad {$ad->name}".($reach !== '' ? " — taken off {$reach}" : ''));

        return response()->json([
            'message' => $reach !== '' ? "Unpublished — taken off {$reach}" : 'Unpublished — it is a draft again',
            'ad' => $this->summary($ad->fresh()),
        ]);
    }

    /**
     * "Show in playlists" (owner's rule, 2026-09-22): may a shop's own playlist play this ad, or is it for
     * channels only? An ad written for a channel, added to the playlist that also carries that channel,
     * plays twice in one pass — so an ad starts channel-only and this is the tick that opens it to playlists.
     *
     * Taking the tick off is refused while a screen still carries the ad, naming the screens: nothing is
     * ever pulled off a television behind somebody's back (the same refusal a file in a channel gets).
     */
    public function showInPlaylists(Request $request, BuilderAd $ad): JsonResponse
    {
        $ad = BuilderAd::visibleTo(auth()->user())->findOrFail($ad->id);

        $validated = $request->validate(['in_playlists' => ['required', 'boolean']]);
        $wanted = (bool) $validated['in_playlists'];

        if (! $wanted && ($stillPlaying = $ad->media?->stillOnScreensMessage()) !== null) {
            throw ValidationException::withMessages(['in_playlists' => $stillPlaying]);
        }

        // A tick is not a change to the design: it must not move updated_at, which would read as changes the
        // screens do not show yet (BuilderAd::hasUnpublishedChanges() — the same reason a poster does not).
        BuilderAd::withoutTimestamps(fn () => $ad->update(['in_playlists' => $wanted]));

        ActivityLog::record(
            'ad.playlists_changed',
            $ad,
            $wanted
                ? "Ad {$ad->name} may now be played from a playlist"
                : "Ad {$ad->name} is now for channels only"
        );

        return response()->json([
            'message' => $wanted
                ? 'Playlists can use this ad now'
                : 'Channels only — a playlist cannot pick this ad',
            'ad' => $this->summary($ad->fresh()),
        ]);
    }

    /** Discard changes: back to the version the screens show (AdPublisher::discardChanges). */
    public function discard(BuilderAd $ad, AdPublisher $publisher): JsonResponse
    {
        $ad = BuilderAd::visibleTo(auth()->user())->findOrFail($ad->id);

        if (! $ad->canDiscardChanges()) {
            throw ValidationException::withMessages(['ad' => match (true) {
                ! $ad->isPublished() => 'This ad is not published, so there is no published version to go back to.',
                ! $ad->hasUnpublishedChanges() => 'There are no changes to discard: the screens show this very design.',
                default => 'This ad was published before its published version was kept, so there is none to go back to. Publish it to keep one.',
            }]);
        }

        $publisher->discardChanges($ad, auth()->id());

        ActivityLog::record('ad.changes_discarded', $ad, "Discarded the unpublished changes of ad {$ad->name}");

        return response()->json([
            'message' => 'Changes discarded — back to the version on the screens',
            'ad' => $this->summary($ad->fresh()),
        ]);
    }

    /**
     * Delete an ad — a big delete, so the password (owner's rule, 2026-09-16). The published copy goes
     * with it, which takes it off every playlist it was on, so the person is told that first.
     */
    public function destroy(Request $request, BuilderAd $ad): JsonResponse
    {
        $ad = BuilderAd::visibleTo(auth()->user())->findOrFail($ad->id);

        // Its published page is a library row; a channel showing it would lose the ad without anybody
        // deciding so (owner, 2026-09-19). Refused before the password, like every other refusal.
        if (($inUse = $ad->media?->stillInAChannelMessage()) !== null) {
            throw ValidationException::withMessages(['name' => $inUse]);
        }

        $this->confirmPassword($request);

        $name = $ad->name;
        $storeId = $ad->store_id;
        $screens = $ad->media_id === null
            ? 0
            : PlaylistItem::where('media_id', $ad->media_id)->count();

        DB::transaction(fn () => $ad->delete());

        ActivityLog::record('ad.deleted', null, "Deleted ad {$name}", storeId: $storeId);

        return response()->json([
            'message' => $screens > 0
                ? "Ad deleted, and taken off {$screens} playlist ".($screens === 1 ? 'line' : 'lines')
                : 'Ad deleted',
        ]);
    }

    /** The pictures and videos the editor may put on the stage. */
    private function assetsForEditor(?int $storeId = null): array
    {
        return BuilderAsset::visibleTo(auth()->user())
            ->when($storeId !== null, fn (Builder $query) => $query->where('store_id', $storeId))
            ->latest()
            ->limit(200)
            ->get(['id', 'store_id', 'title', 'kind', 'disk', 'path', 'thumbnail_path', 'width', 'height', 'duration_seconds'])
            ->toArray();
    }

    /**
     * The shops a platform person may filter the listing by — none for a store's own people, who only
     * ever see their own shop.
     *
     * @return array<int, array{id: int, name: string}>
     */
    private function storesToFilterBy(): array
    {
        return auth()->user()->globalRole() !== null
            ? Store::orderBy('name')->get(['id', 'name'])->toArray()
            : [];
    }

    /** What the listing and the editor need to know about an ad — never its whole document. */
    private function summary(BuilderAd $ad): array
    {
        return [
            'id' => $ad->id,
            'name' => $ad->name,
            'orientation' => $ad->orientation,
            'thumbnail_url' => $ad->thumbnail_url,
            'is_published' => $ad->isPublished(),
            // draft | published | changed — changed: on the screens, with saved changes they do not show yet.
            'status' => $ad->status(),
            'has_published_version' => $ad->hasPublishedVersion(),
            // May a shop's own playlist play it, or is it for channels only (showInPlaylists)?
            'in_playlists' => (bool) $ad->in_playlists,
            'updated_at' => $ad->updated_at?->toIso8601String(),
        ];
    }

    /** "2 screens and 1 channel", "1 screen", or nothing at all. */
    private function reachInWords(int $screens, int $channels): string
    {
        return implode(' and ', array_filter([
            $screens > 0 ? $screens.' '.($screens === 1 ? 'screen' : 'screens') : null,
            $channels > 0 ? $channels.' '.($channels === 1 ? 'channel' : 'channels') : null,
        ]));
    }

    /** The editor photographs its own stage, because there is no browser on the server to do it. */
    private function savePoster(BuilderAd $ad, ?string $dataUri): void
    {
        if (! is_string($dataUri) || $dataUri === '') {
            return;
        }

        $path = $this->storage->storePoster("{$ad->storageDirectory()}/poster.jpg", $dataUri);

        // A photograph of the design is not a change to it: it must not move `updated_at`, which would make a
        // published ad read as edited since — a draft, off every screen (BuilderAd::isPublished()).
        if ($path !== null) {
            BuilderAd::withoutTimestamps(fn () => $ad->update(['thumbnail_path' => $path]));
        }
    }

    /** "Winter sale" → "Winter sale (copy)", and "(copy 2)" after that. */
    private function copyName(BuilderAd $ad): string
    {
        $base = preg_replace('/ \(copy( \d+)?\)$/', '', $ad->name) ?? $ad->name;
        $taken = BuilderAd::where('store_id', $ad->store_id)->pluck('name')->all();

        if (! in_array("{$base} (copy)", $taken, true)) {
            return mb_substr("{$base} (copy)", 0, 120);
        }

        for ($i = 2; $i < 100; $i++) {
            if (! in_array("{$base} (copy {$i})", $taken, true)) {
                return mb_substr("{$base} (copy {$i})", 0, 120);
            }
        }

        return mb_substr("{$base} (copy)", 0, 120);
    }

    /**
     * Which shop the new ad belongs to.
     *
     * A store's person builds in the store they are working in — there is nothing to choose. The platform
     * team stands in no store at all (the tiers are exclusive), so they say which shop the ad is for, and
     * the answer has to be a store that exists. Either way an ad is never store-less: the media row it
     * publishes into cannot be.
     *
     * @param  array<string, mixed>  $validated
     */
    private function targetStoreId(array $validated): int
    {
        if (auth()->user()->globalRole() !== null) {
            $storeId = (int) ($validated['store_id'] ?? 0);

            if (! $storeId || ! Store::whereKey($storeId)->exists()) {
                throw ValidationException::withMessages([
                    'store_id' => 'Choose the shop this ad is for.',
                ]);
            }

            return $storeId;
        }

        $storeId = (int) session('current_store_id');

        if (! $storeId) {
            throw ValidationException::withMessages([
                'name' => 'Select a store before saving an ad — an ad belongs to the shop it was made for.',
            ]);
        }

        return $storeId;
    }
}
