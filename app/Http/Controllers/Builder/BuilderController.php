<?php

namespace App\Http\Controllers\Builder;

use App\Http\Controllers\Concerns\ConfirmsPassword;
use App\Http\Controllers\Concerns\HandlesCrudData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Builder\BuilderAdRequest;
use App\Models\ActivityLog;
use App\Models\BuilderAd;
use App\Models\BuilderAsset;
use App\Models\Media;
use App\Models\PlaylistItem;
use App\Models\Store;
use App\Services\AdCompiler;
use App\Services\AdPublisher;
use App\Services\MediaStorage;
use App\Services\StoreStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The Ad Builder (docs/AD-BUILDER-SPEC.md): two pages — Ads and Assets — around one editor that
 * draws an advert the shape of a television: 1920×1080, or 1080×1920 for one mounted upright (§12).
 *
 * An ad belongs to a store, like everything else a shop makes, and the platform works above them all:
 * `BuilderAd::visibleTo` decides which, so a store's person never sees another shop's design and a super
 * admin sees every one with the shop's name beside it. The platform may also make an ad for every shop (owner,
 * 2026-10-01): every shop sees it once it is published and copies it into its own Ads, and only above the stores is
 * it changed or deleted — with the ordinary Update Ads and Delete Ads there; a shop's people see, use and copy it,
 * nothing more ("srif delete nahi kar sakta ha"). Each action asks it of the ad (mayUpdate, mayDelete).
 */
class BuilderController extends Controller
{
    use ConfirmsPassword, HandlesCrudData;

    public function __construct(private readonly MediaStorage $storage, private readonly StoreStorage $quota) {}

    /** The Ads page: everything this person may open — and, above the stores, a filter by shop. */
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
            ->with(['store:id,name', 'updater:id,first_name,last_name', 'media:id,thumbnail_path'])
            ->latest('updated_at');

        return $this->paginatedResponse(
            $request,
            $query,
            // A shop finds the platform's ad by the name it was published under, which is the one its card shows.
            ['name', 'published_name'],
            'ads',
            ['*'],
            // Each row says whether a television shows it — and whether it shows the latest changes — who touched it
            // last, whose it is, what this person may do to it, and why it may not be deleted yet (a channel shows its
            // page), so the gallery says it before the password is asked.
            function (Collection $rows) {
                $refusals = Media::stillInChannelsMessages($rows->pluck('media_id')->filter()->all());

                $rows->each(function (BuilderAd $ad) use ($refusals) {
                    $ad->setAttribute('is_published', $ad->isPublished());
                    $ad->setAttribute('status', $ad->status());
                    $ad->setAttribute('store_name', $ad->store?->name);
                    $ad->setAttribute('updated_by_name', $ad->updater?->name);
                    $ad->setAttribute('in_channels_message', $ad->media_id === null ? null : ($refusals[$ad->media_id] ?? null));
                    $ad->setAttribute('shared', $ad->isShared());
                    $ad->setAttribute('owner_label', $this->ownerLabel($ad));
                    $ad->setAttribute('can', ['update' => $this->mayUpdate($ad), 'copy' => $this->mayCopy($ad), 'delete' => $this->mayDelete($ad)]);

                    // Inside a shop the platform's ad is the platform's: shown as it was published — never its unfinished
                    // changes — and with nobody's name from above the stores (as the platform's channels are shown).
                    if ($ad->isShared() && ! $this->aboveTheStores()) {
                        $ad->setAttribute('updated_by_name', null);
                        $ad->makeHidden(['created_by', 'updated_by']);

                        if ($this->showsPublishedVersion($ad)) {
                            $ad->setAttribute('name', $ad->published_name ?? $ad->name);
                            $ad->setAttribute('thumbnail_path', $ad->media?->thumbnail_path);
                        }
                    }

                    // The listing shows a poster and a name — never the whole design, draft or published.
                    $ad->makeHidden(['document', 'published_document', 'published_name', 'media']);
                });
            },
        );
    }

    /**
     * A new ad: the editor with an empty stage of the shape New ad asked for. Nothing is written until the
     * first save. The platform team says which shop a new ad is for, so they are handed the shops to choose
     * from.
     */
    public function create(Request $request): View|RedirectResponse
    {
        $orientation = $request->query('orientation');

        // Which way the screen is mounted comes first (docs/AD-BUILDER-SPEC.md §12): chosen once, fixed after.
        // With no answer — none, a word nobody offers, or a list — the address goes to the Ads page with New ad's
        // question open (there is no Create tab any more), so nobody reaches the editor without being asked.
        if (! in_array($orientation, [BuilderAd::LANDSCAPE, BuilderAd::PORTRAIT], true)) {
            return redirect()->route('builder.index', ['new' => 1]);
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
        // or it does not exist for them (404, never 403) — and a shared one opens above the stores alone.
        $ad = BuilderAd::visibleTo(auth()->user())->findOrFail($ad->id);
        $this->authorizeChange($ad);

        return view('builder.editor', [
            'ad' => $ad,
            'orientation' => $ad->orientation,
            'document' => $ad->document,
            'assets' => $this->assetsForEditor($ad),
            'ownerLabel' => $this->ownerLabel($ad) ?? ($this->aboveTheStores() ? $ad->store?->name : null),
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

        ActivityLog::record('ad.created', $ad, $ad->isShared()
            ? "Created {$ad->orientation} ad {$ad->name} for every shop"
            : "Created {$ad->orientation} ad {$ad->name}", storeId: $this->logStoreOf($ad));

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
        $this->authorizeChange($ad);
        $validated = $request->validated();
        $changed = $ad->wouldChangeWith($validated['name'], $validated['document']);

        if ($changed) {
            $ad->forceFill([
                'name' => $validated['name'],
                'document' => $validated['document'],
                'updated_by' => auth()->id(),
            ])->save();

            ActivityLog::record('ad.updated', $ad, "Updated ad {$ad->name}", storeId: $this->logStoreOf($ad));
        }

        $this->savePoster($ad, $request->input('thumbnail'));

        return response()->json([
            'message' => $changed && $ad->isPublished()
                ? ($ad->isShared()
                    ? 'Changes saved — shops keep the published version until you publish them'
                    : 'Changes saved — the screens keep the published version until you publish them')
                : 'Ad saved',
            'ad' => $this->summary($ad->fresh()),
        ]);
    }

    /**
     * A copy to work from, with its own name. The copy is a draft even if the original was published.
     *
     * Inside a shop a copy is that shop's own, whichever ad it was made from — the platform's shared ones included
     * (owner, 2026-10-01: "woo copy kar sake"), and then it is of what the shop was shown: the version the platform
     * published, never its unfinished changes, under that name while the shop has no ad called so. Above the stores a
     * copy stays where its original is: a shared ad's copy is shared too, as every ad made there with no shop is.
     */
    public function duplicate(BuilderAd $ad): JsonResponse
    {
        $ad = BuilderAd::visibleTo(auth()->user())->findOrFail($ad->id);
        $storeId = $this->aboveTheStores() ? $ad->store_id : $this->standingStoreId();
        $fromThePlatform = $ad->isShared() && $storeId !== null;
        $published = $fromThePlatform && $ad->isPublished() && $ad->published_document !== null;
        $name = $published ? ($ad->published_name ?? $ad->name) : $ad->name;
        $original = $published ? $ad->media?->thumbnail_path : $ad->thumbnail_path;

        $copy = BuilderAd::create([
            'store_id' => $storeId,
            'name' => $this->copyName($name, $storeId, keepItIfFree: $fromThePlatform),
            'orientation' => $ad->orientation,
            'document' => $published ? $ad->published_document : $ad->document,
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        // The poster is the design's picture, so the copy starts with it — as a file of its own. Sharing the
        // original's file would let deleting the original take the copy's picture with it. A shop with no room
        // for it gets its copy without one: a poster is never a reason to refuse (StoreStorage).
        if ($original && Storage::disk('public')->exists($original)) {
            $poster = $copy->storageDirectory().'/poster.jpg';

            $this->quota->withRoomOrSkip($copy->store_id, (int) Storage::disk('public')->size($original), function () use ($original, $copy, $poster) {
                Storage::disk('public')->copy($original, $poster);
                $copy->update(['thumbnail_path' => $poster]);

                return true;
            });
        }

        if ($fromThePlatform) {
            ActivityLog::record('ad.copied', $copy, "Copied ad {$name} from the platform".($copy->name !== $name ? " as {$copy->name}" : ''));
        } else {
            ActivityLog::record('ad.duplicated', $copy, "Duplicated ad {$ad->name} as {$copy->name}", storeId: $this->logStoreOf($copy));
        }

        return response()->json([
            'message' => $fromThePlatform ? 'Copied to your ads' : 'Ad duplicated',
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
    public function preview(Request $request, BuilderAd $ad, AdCompiler $compiler): Response
    {
        // Whoever may look at the ads, and whoever may change this one: previewing is part of designing.
        abort_unless(auth()->user()->canAny(['ad-view', 'ad-update']), 403);

        $ad = BuilderAd::visibleTo(auth()->user())->findOrFail($ad->id);

        // A shop is shown the platform's ad as it was published, as its gallery shows it — never the changes the
        // platform has not published yet. Only read, never saved.
        if ($this->showsPublishedVersion($ad) && $ad->published_document !== null) {
            $ad = (clone $ad)->forceFill(['document' => $ad->published_document, 'name' => $ad->published_name ?? $ad->name]);
        }

        // A preview reloads itself at the ad's length for as long as its tab is open: it is compiled again only when
        // the draft has changed since (the brute-force round, 2026-09-29 — a tab left open overnight compiled the
        // ad thousands of times on a one-core server). The browser keeps it privately and asks every time.
        $etag = '"'.hash('sha256', AdCompiler::VERSION.'|'.$ad->id.'|'.$ad->updated_at?->toIso8601String().'|'.json_encode($ad->document)).'"';
        $headers = [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Security-Policy' => 'sandbox allow-scripts',
            'Cache-Control' => 'no-cache, private',
            'ETag' => $etag,
        ];

        if (in_array($etag, $request->getETags(), true)) {
            return response('', 304, $headers);
        }

        // As a screen shows it: for the ad's own length, then from the start again, as a playlist of this one ad
        // would — a video in it cut at the end. A published page never carries this: the player times it. A design
        // with no length of its own has none to go by: each screen gives it its own.
        $page = $compiler->compile($ad);

        if (BuilderAd::hasOwnLength($ad->document)) {
            $refresh = '<meta http-equiv="refresh" content="'.BuilderAd::lengthOf($ad->document).'">';
            $page = Str::replaceFirst('<meta charset="utf-8">', '<meta charset="utf-8">'."\n".$refresh, $page);
        }

        return response($page, 200, $headers);
    }

    /**
     * Publish: compile the design into a page and put it in the library, where a playlist can reach it
     * (AdPublisher says how, and why the media row keeps its id).
     */
    public function publish(BuilderAd $ad, AdPublisher $publisher): JsonResponse
    {
        $ad = BuilderAd::visibleTo(auth()->user())->findOrFail($ad->id);
        $this->authorizeChange($ad);

        $media = $publisher->publish($ad, auth()->id());

        ActivityLog::record('ad.published', $ad, $ad->isShared() ? "Published ad {$ad->name} for every shop" : "Published ad {$ad->name}",
            storeId: $this->logStoreOf($ad));

        $screens = $publisher->screensShowing($media);

        return response()->json([
            'message' => match (true) {
                // Its page is in the platform's library, where only the platform's channels reach it; every shop now
                // sees this version, and copies it to play it on its own screens.
                $ad->isShared() => 'Published — every shop sees it now and can copy it',
                $screens > 0 => "Published — {$screens} ".($screens === 1 ? 'screen is' : 'screens are').' now showing the new version',
                default => 'Published to your media library, ready for a playlist',
            },
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
        $this->authorizeChange($ad);

        if (! $ad->isPublished()) {
            throw ValidationException::withMessages(['ad' => 'This ad is not on any screen.']);
        }

        $reach = $this->reachInWords($publisher->screensShowing($ad->media), $publisher->channelsShowing($ad->media));

        $publisher->unpublish($ad);

        ActivityLog::record('ad.unpublished', $ad, "Unpublished ad {$ad->name}".($reach !== '' ? " — taken off {$reach}" : ''),
            storeId: $this->logStoreOf($ad));

        // A shared ad leaves every shop's Ads too, until it is published again; the copies shops made stay theirs.
        $message = $reach !== '' ? "Unpublished — taken off {$reach}" : 'Unpublished — it is a draft again';

        return response()->json([
            'message' => $ad->isShared() ? "{$message}. Shops no longer see it" : $message,
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
        $this->authorizeChange($ad);

        $validated = $request->validate(['in_playlists' => ['required', 'boolean']]);
        $wanted = (bool) $validated['in_playlists'];

        // Its page is in the platform's library, which no shop's playlist reaches: a shop plays it from its own copy.
        if ($ad->isShared()) {
            throw ValidationException::withMessages([
                'in_playlists' => 'An ad for every shop plays only in the platform\'s channels. A shop copies it to put it on its playlists.',
            ]);
        }

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
        $this->authorizeChange($ad);

        if (! $ad->canDiscardChanges()) {
            throw ValidationException::withMessages(['ad' => match (true) {
                ! $ad->isPublished() => 'This ad is not published, so there is no published version to go back to.',
                ! $ad->hasUnpublishedChanges() => 'There are no changes to discard: the screens show this very design.',
                default => 'This ad was published before its published version was kept, so there is none to go back to. Publish it to keep one.',
            }]);
        }

        $publisher->discardChanges($ad, auth()->id());

        ActivityLog::record('ad.changes_discarded', $ad, "Discarded the unpublished changes of ad {$ad->name}", storeId: $this->logStoreOf($ad));

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

        // A shared ad goes from every shop at once: the platform's alone to delete (owner, 2026-10-01).
        abort_unless($this->mayDelete($ad), 403, $ad->isShared() && ! $this->aboveTheStores()
            ? 'An ad for every shop is the platform\'s: only the platform deletes it.'
            : 'Deleting an ad needs the Delete Ads permission.');

        // Its published page is a library row; a channel showing it would lose the ad without anybody
        // deciding so (owner, 2026-09-19). Refused before the password, like every other refusal.
        if (($inUse = $ad->media?->stillInAChannelMessage()) !== null) {
            throw ValidationException::withMessages(['name' => $inUse]);
        }

        $this->confirmPassword($request);

        $name = $ad->name;
        $shared = $ad->isShared();
        $storeId = $this->logStoreOf($ad);
        $screens = $ad->media_id === null
            ? 0
            : PlaylistItem::where('media_id', $ad->media_id)->count();

        DB::transaction(fn () => $ad->delete());

        // The copies shops made of a shared ad are theirs, and stay.
        ActivityLog::record('ad.deleted', null, $shared ? "Deleted ad {$name}, shared with every shop" : "Deleted ad {$name}", storeId: $storeId);

        return response()->json([
            'message' => $screens > 0
                ? "Ad deleted, and taken off {$screens} playlist ".($screens === 1 ? 'line' : 'lines')
                : 'Ad deleted',
        ]);
    }

    /**
     * The pictures and videos the editor may put on the stage: the ad's shop's own and those the platform shares
     * with every shop (owner, 2026-09-29) — for an ad shared with every shop, the shared ones alone. A new ad's are
     * everything in reach, and the editor keeps to the shelf of the shop chosen for it (onThisShelf).
     */
    private function assetsForEditor(?BuilderAd $ad = null): array
    {
        return BuilderAsset::visibleTo(auth()->user())
            ->when($ad !== null, fn (Builder $query) => $query->onShelfOf($ad->store_id))
            ->latest()
            ->limit(200)
            ->get(['id', 'store_id', 'title', 'kind', 'disk', 'path', 'thumbnail_path', 'width', 'height', 'duration_seconds'])
            ->toArray();
    }

    /** Update Ads — and, for an ad the platform shares with every shop, standing above the stores. */
    private function mayUpdate(BuilderAd $ad): bool
    {
        return Gate::allows('ad-update') && (! $ad->isShared() || $this->aboveTheStores());
    }

    /** Delete Ads — and, for an ad the platform shares with every shop, standing above the stores. */
    private function mayDelete(BuilderAd $ad): bool
    {
        return Gate::allows('ad-destroy') && (! $ad->isShared() || $this->aboveTheStores());
    }

    /** Create Ads: inside a shop the copy is the shop's own, above the stores it stays where its original is. */
    private function mayCopy(BuilderAd $ad): bool
    {
        return Gate::allows('ad-store');
    }

    /** Changing, publishing and taking an ad off: refused with the reason, never as a bare 403. */
    private function authorizeChange(BuilderAd $ad): void
    {
        abort_unless($this->mayUpdate($ad), 403, $ad->isShared() && ! $this->aboveTheStores()
            ? 'An ad for every shop is the platform\'s: copy it to change it.'
            : 'Changing an ad needs the Update Ads permission.');
    }

    /** Whether a shop's person is shown the version the platform published rather than its draft: always, inside a shop. */
    private function showsPublishedVersion(BuilderAd $ad): bool
    {
        return $ad->isShared() && $ad->isPublished() && ! $this->aboveTheStores();
    }

    /** Whose ad this is, for a shared one: above the stores "Every shop", inside a shop "From the platform". */
    private function ownerLabel(BuilderAd $ad): ?string
    {
        if (! $ad->isShared()) {
            return null;
        }

        return $this->aboveTheStores() ? 'Every shop' : 'From the platform';
    }

    /** The store a log entry belongs to: the ad's shop, or for a shared ad the shop the person is working in (if any). */
    private function logStoreOf(BuilderAd $ad): ?int
    {
        return $ad->store_id ?? $this->standingStoreId();
    }

    private function aboveTheStores(): bool
    {
        return auth()->user()->globalRole() !== null;
    }

    /** The shop a store's person is working in; none above the stores. */
    private function standingStoreId(): ?int
    {
        return $this->aboveTheStores() ? null : ((int) session('current_store_id') ?: null);
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
            // Made for every shop, by the platform (owner, 2026-10-01)?
            'shared' => $ad->isShared(),
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

        // Only while the shop has room for it — written and recorded on the design under the shop's lock, so it is
        // counted from the moment it exists; without room the design keeps the poster it had (StoreStorage). A
        // photograph of the design is not a change to it: it must not move `updated_at`, which would make a
        // published ad read as edited since — a draft, off every screen (BuilderAd::isPublished()).
        $this->storage->storePosterWithin($ad->store_id, "{$ad->storageDirectory()}/poster.jpg", $dataUri,
            fn (string $path) => BuilderAd::withoutTimestamps(fn () => $ad->update(['thumbnail_path' => $path])));
    }

    /**
     * "Winter sale" → "Winter sale (copy)", and "(copy 2)" after that, among the ads of the place the copy goes to. A
     * shop's copy of the platform's ad keeps the name while the shop has no ad called so: it is the shop's first.
     */
    private function copyName(string $name, ?int $storeId, bool $keepItIfFree = false): string
    {
        $base = preg_replace('/ \(copy( \d+)?\)$/', '', $name) ?? $name;
        $taken = BuilderAd::query()
            ->when($storeId === null, fn (Builder $query) => $query->whereNull('store_id'), fn (Builder $query) => $query->where('store_id', $storeId))
            ->pluck('name')
            ->all();

        if ($keepItIfFree && ! in_array($name, $taken, true)) {
            return mb_substr($name, 0, 120);
        }

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
     * Which shop the new ad belongs to — or none, for an ad the platform makes for every shop.
     *
     * A store's person builds in the store they are working in — there is nothing to choose. The platform
     * team stands in no store at all (the tiers are exclusive), so they say which shop the ad is for, and
     * the answer has to be a store that exists; or no shop at all — All shops, the editor's first choice
     * (owner, 2026-10-01) — and the ad is shared with every shop.
     *
     * @param  array<string, mixed>  $validated
     */
    private function targetStoreId(array $validated): ?int
    {
        if ($this->aboveTheStores()) {
            $storeId = (int) ($validated['store_id'] ?? 0);

            if ($storeId === 0) {
                return null;
            }

            if (! Store::whereKey($storeId)->exists()) {
                throw ValidationException::withMessages([
                    'store_id' => 'That shop no longer exists. Reload the page and choose again.',
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
