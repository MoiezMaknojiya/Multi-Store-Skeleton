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
use App\Models\Organization;
use App\Models\PlaylistItem;
use App\Services\AdCompiler;
use App\Services\AdPublisher;
use App\Services\MediaStorage;
use App\Services\OrganizationStorage;
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
 * An ad belongs to an organization, like everything else an organization makes, and the platform works above them all:
 * `BuilderAd::visibleTo` decides which, so an organization's person never sees another organization's design and a super
 * admin sees every one with the organization's name beside it. The platform may also make an ad for every organization (owner,
 * 2026-10-01), changed and deleted above the organizations alone, with the ordinary Update Ads and Delete Ads there. Published,
 * it is a Premium Template (owner, 2026-10-07): an organization finds it under Create Ad and makes its own copy, files and all
 * (PremiumTemplateController) — it is never listed, opened or copied from an organization's Ads page.
 */
class BuilderController extends Controller
{
    use ConfirmsPassword, HandlesCrudData;

    public function __construct(private readonly MediaStorage $storage, private readonly OrganizationStorage $quota) {}

    /**
     * The Ads page: everything this person may open — and, above the organizations, a filter by organization. Inside an
     * organization its Create Ad also offers the Premium Templates (PremiumTemplateController).
     */
    public function index(): View
    {
        return view('builder.index', [
            'organizations' => $this->organizationsToFilterBy(),
            'aboveTheOrganizations' => $this->aboveTheOrganizations(),
            // Said in the Premium Templates' lock and its unlock dialog (docs/BILLING-SPEC.md §6).
            'organizationName' => $this->aboveTheOrganizations() ? null : Organization::whereKey($this->standingOrganizationId())->value('name'),
        ]);
    }

    /** The saved ads, newest first. */
    public function data(Request $request): JsonResponse
    {
        // Above the organizations the Owner list (owner, 2026-10-08): the platform's own ads (`platform`, where the page opens), one
        // organization's (its id), or all of them (nothing sent).
        $filters = $request->validate(['organization_id' => ['nullable', 'regex:/^(platform|[1-9][0-9]{0,9})$/']]);
        $owner = $filters['organization_id'] ?? null;

        // The filter only ever NARROWS what visibleTo allows: inside an organization, asking for another organization's
        // ads finds none, because the organization's own wall is already on the query.
        $query = BuilderAd::visibleTo(auth()->user())
            ->when($owner === 'platform', fn (Builder $query) => $query->whereNull('organization_id'))
            ->when($owner !== null && $owner !== 'platform', fn (Builder $query) => $query->where('organization_id', (int) $owner))
            ->with(['organization:id,name', 'updater:id,first_name,last_name', 'media:id,thumbnail_path'])
            ->latest('updated_at');

        return $this->paginatedResponse(
            $request,
            $query,
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
                    $ad->setAttribute('organization_name', $ad->organization?->name);
                    $ad->setAttribute('updated_by_name', $ad->updater?->name);
                    $ad->setAttribute('in_channels_message', $ad->media_id === null ? null : ($refusals[$ad->media_id] ?? null));
                    $ad->setAttribute('shared', $ad->isShared());
                    $ad->setAttribute('owner_label', $this->ownerLabel($ad));
                    $ad->setAttribute('from_template', $ad->copied_from_id !== null);
                    $ad->setAttribute('can', ['update' => $this->mayUpdate($ad), 'copy' => $this->mayCopy($ad), 'delete' => $this->mayDelete($ad)]);

                    // The listing shows a poster and a name — never the whole design, draft or published.
                    $ad->makeHidden(['document', 'published_document', 'published_name', 'media']);
                });
            },
        );
    }

    /**
     * A new ad: the editor with an empty stage of the shape New ad asked for. Nothing is written until the
     * first save. The platform team says which organization a new ad is for, so they are handed the organizations to choose
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
            'organizations' => $this->organizationsToFilterBy(),
        ]);
    }

    /** The editor, opened on a saved ad. */
    public function edit(BuilderAd $ad): View
    {
        // Route middleware is not enough: the target has to be inside the organization the actor is working in,
        // or it does not exist for them (404, never 403) — and a shared one opens above the organizations alone.
        $ad = BuilderAd::visibleTo(auth()->user())->findOrFail($ad->id);
        $this->authorizeChange($ad);

        return view('builder.editor', [
            'ad' => $ad,
            'orientation' => $ad->orientation,
            'document' => $ad->document,
            'assets' => $this->assetsForEditor($ad),
            'ownerLabel' => $this->ownerLabel($ad),
        ]);
    }

    /** Save a new ad. */
    public function store(BuilderAdRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $organizationId = $this->targetOrganizationId($validated);

        $ad = BuilderAd::create([
            'organization_id' => $organizationId,
            'name' => $validated['name'],
            // Said once, here; from now on the column decides what size a save may be (§12).
            'orientation' => $request->orientation(),
            'document' => $validated['document'],
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        $this->savePoster($ad, $request->input('thumbnail'));

        ActivityLog::record('ad.created', $ad, $ad->isShared()
            ? "Created {$ad->orientation} ad {$ad->name} for every organization"
            : "Created {$ad->orientation} ad {$ad->name}", organizationId: $this->logOrganizationOf($ad));

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

            ActivityLog::record('ad.updated', $ad, "Updated ad {$ad->name}", organizationId: $this->logOrganizationOf($ad));
        }

        $this->savePoster($ad, $request->input('thumbnail'));

        return response()->json([
            'message' => $changed && $ad->isPublished()
                ? ($ad->isShared()
                    ? 'Changes saved — organizations keep the published version until you publish them'
                    : 'Changes saved — the screens keep the published version until you publish them')
                : 'Ad saved',
            'ad' => $this->summary($ad->fresh()),
        ]);
    }

    /**
     * A copy to work from, with its own name, where its original is: an organization's in that organization, a shared ad's shared
     * too. The copy is a draft even if the original was published. (An organization makes its own of the platform's ad with
     * Use This Template, which copies its files as well — PremiumTemplateController.)
     */
    public function duplicate(BuilderAd $ad): JsonResponse
    {
        $ad = BuilderAd::visibleTo(auth()->user())->findOrFail($ad->id);
        $organizationId = $ad->organization_id;
        $original = $ad->thumbnail_path;

        $copy = BuilderAd::create([
            'organization_id' => $organizationId,
            'name' => BuilderAd::nameForCopy($ad->name, $organizationId),
            'orientation' => $ad->orientation,
            'document' => $ad->document,
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        // The poster is the design's picture, so the copy starts with it — as a file of its own. Sharing the
        // original's file would let deleting the original take the copy's picture with it. An organization with no room
        // for it gets its copy without one: a poster is never a reason to refuse (OrganizationStorage).
        if ($original && Storage::disk('public')->exists($original)) {
            $poster = $copy->storageDirectory().'/poster.jpg';

            $this->quota->withRoomOrSkip($copy->organization_id, (int) Storage::disk('public')->size($original), function () use ($original, $copy, $poster) {
                Storage::disk('public')->copy($original, $poster);
                $copy->update(['thumbnail_path' => $poster]);

                return true;
            });
        }

        ActivityLog::record('ad.duplicated', $copy, "Duplicated ad {$ad->name} as {$copy->name}", organizationId: $this->logOrganizationOf($copy));

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
    public function preview(Request $request, BuilderAd $ad, AdCompiler $compiler): Response
    {
        // Whoever may look at the ads, and whoever may change this one: previewing is part of designing — and whoever may
        // make an ad, for a Premium Template.
        abort_unless(auth()->user()->canAny(['ad-view', 'ad-update', 'ad-store']), 403);

        $own = BuilderAd::visibleTo(auth()->user())->find($ad->id);

        if ($own !== null) {
            abort_unless(auth()->user()->canAny(['ad-view', 'ad-update']), 403);
            $ad = $own;
        } else {
            // Inside an organization a Premium Template is shown as it was published — never the changes the platform has not
            // published yet — to whoever may make an ad from it. Only read, never saved.
            abort_if($this->aboveTheOrganizations(), 404);
            $template = BuilderAd::premiumTemplates()->findOrFail($ad->id);
            abort_unless(auth()->user()->can('ad-store'), 403);
            $ad = (clone $template)->forceFill(['document' => $template->published_document, 'name' => $template->published_name ?? $template->name]);
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

        ActivityLog::record('ad.published', $ad, $ad->isShared() ? "Published ad {$ad->name} for every organization" : "Published ad {$ad->name}",
            organizationId: $this->logOrganizationOf($ad));

        $screens = $publisher->screensShowing($media);

        return response()->json([
            'message' => match (true) {
                // A Premium Template now: every organization finds this version under Create Ad.
                $ad->isShared() => 'Published — every organization finds it under Premium Template now',
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
            organizationId: $this->logOrganizationOf($ad));

        // A shared ad is no Premium Template until it is published again; the copies organizations made stay theirs.
        $message = $reach !== '' ? "Unpublished — taken off {$reach}" : 'Unpublished — it is a draft again';

        return response()->json([
            'message' => $ad->isShared() ? "{$message}. It is no Premium Template until it is published again" : $message,
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

        ActivityLog::record('ad.changes_discarded', $ad, "Discarded the unpublished changes of ad {$ad->name}", organizationId: $this->logOrganizationOf($ad));

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

        // An ad for every organization is the platform's alone to delete (owner, 2026-10-01).
        abort_unless($this->mayDelete($ad), 403, 'Deleting an ad needs the Delete Ads permission.');

        // Its published page is a library row; a channel showing it would lose the ad without anybody
        // deciding so (owner, 2026-09-19). Refused before the password, like every other refusal.
        if (($inUse = $ad->media?->stillInAChannelMessage()) !== null) {
            throw ValidationException::withMessages(['name' => $inUse]);
        }

        $this->confirmPassword($request);

        $name = $ad->name;
        $shared = $ad->isShared();
        $organizationId = $this->logOrganizationOf($ad);
        $screens = $ad->media_id === null
            ? 0
            : PlaylistItem::where('media_id', $ad->media_id)->count();

        DB::transaction(fn () => $ad->delete());

        // The copies organizations made of a Premium Template are theirs, files and all, and stay.
        ActivityLog::record('ad.deleted', null, $shared ? "Deleted ad {$name}, shared with every organization" : "Deleted ad {$name}", organizationId: $organizationId);

        return response()->json([
            'message' => $screens > 0
                ? "Ad deleted, and taken off {$screens} playlist ".($screens === 1 ? 'line' : 'lines')
                : 'Ad deleted',
        ]);
    }

    /**
     * The pictures and videos the editor may put on the stage: the ad's organization's own alone (owner, 2026-10-07), and for an
     * ad for every organization the platform's alone (BuilderAsset::onShelfOf). A new ad's are everything in reach, and the
     * editor keeps to the shelf of the organization chosen for it (onThisShelf).
     */
    private function assetsForEditor(?BuilderAd $ad = null): array
    {
        return BuilderAsset::visibleTo(auth()->user())
            ->when($ad !== null, fn (Builder $query) => $query->onShelfOf($ad->organization_id))
            ->latest()
            ->limit(200)
            ->get(['id', 'organization_id', 'title', 'kind', 'disk', 'path', 'thumbnail_path', 'width', 'height', 'duration_seconds'])
            ->toArray();
    }

    /** Update Ads — and, for an ad for every organization, standing above the organizations (an organization never sees one). */
    private function mayUpdate(BuilderAd $ad): bool
    {
        return Gate::allows('ad-update') && (! $ad->isShared() || $this->aboveTheOrganizations());
    }

    /** Delete Ads — and, for an ad the platform shares with every organization, standing above the organizations. */
    private function mayDelete(BuilderAd $ad): bool
    {
        return Gate::allows('ad-destroy') && (! $ad->isShared() || $this->aboveTheOrganizations());
    }

    /** Create Ads: the copy is made where its original is. */
    private function mayCopy(BuilderAd $ad): bool
    {
        return Gate::allows('ad-store');
    }

    /** Changing, publishing and taking an ad off: refused with the reason, never as a bare 403. */
    private function authorizeChange(BuilderAd $ad): void
    {
        abort_unless($this->mayUpdate($ad), 403, 'Changing an ad needs the Update Ads permission.');
    }

    /** Whose ad this is, for one made for every organization: "Every organization" (only the platform ever lists one). */
    /**
     * Whose the ad is, on its card above the organizations, where a template and an organization's copy of it look alike (owner,
     * 2026-10-08): the platform's — a Premium Template once published — or the organization's name. Inside an organization every
     * ad is its own, so nothing is said.
     */
    private function ownerLabel(BuilderAd $ad): ?string
    {
        if (! $this->aboveTheOrganizations()) {
            return null;
        }

        if ($ad->isShared()) {
            return $ad->isPublished() ? 'Premium Template' : 'Platform';
        }

        return $ad->organization?->name;
    }

    /** The organization a log entry belongs to: the ad's organization, or for a shared ad the organization the person is working in (if any). */
    private function logOrganizationOf(BuilderAd $ad): ?int
    {
        return $ad->organization_id ?? $this->standingOrganizationId();
    }

    private function aboveTheOrganizations(): bool
    {
        return auth()->user()->globalRole() !== null;
    }

    /** The organization an organization's person is working in; none above the organizations. */
    private function standingOrganizationId(): ?int
    {
        return $this->aboveTheOrganizations() ? null : ((int) session('current_organization_id') ?: null);
    }

    /**
     * The organizations a platform person may filter the listing by — none for an organization's own people, who only
     * ever see their own organization.
     *
     * @return array<int, array{id: int, name: string}>
     */
    private function organizationsToFilterBy(): array
    {
        return auth()->user()->globalRole() !== null
            ? Organization::orderBy('name')->get(['id', 'name'])->toArray()
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
            // Made for every organization, by the platform (owner, 2026-10-01)?
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

        // Only while the organization has room for it — written and recorded on the design under the organization's lock, so it is
        // counted from the moment it exists; without room the design keeps the poster it had (OrganizationStorage). A
        // photograph of the design is not a change to it: it must not move `updated_at`, which would make a
        // published ad read as edited since — a draft, off every screen (BuilderAd::isPublished()).
        $this->storage->storePosterWithin($ad->organization_id, "{$ad->storageDirectory()}/poster.jpg", $dataUri,
            fn (string $path) => BuilderAd::withoutTimestamps(fn () => $ad->update(['thumbnail_path' => $path])));
    }

    /**
     * Which organization the new ad belongs to — or none, for an ad the platform makes for every organization.
     *
     * An organization's person builds in the organization they are working in — there is nothing to choose. The platform
     * team stands in no organization at all (the tiers are exclusive), so they say which organization the ad is for, and
     * the answer has to be an organization that exists; or no organization at all — All organizations, the editor's first choice
     * (owner, 2026-10-01) — and the ad is shared with every organization.
     *
     * @param  array<string, mixed>  $validated
     */
    private function targetOrganizationId(array $validated): ?int
    {
        if ($this->aboveTheOrganizations()) {
            $organizationId = (int) ($validated['organization_id'] ?? 0);

            if ($organizationId === 0) {
                return null;
            }

            if (! Organization::whereKey($organizationId)->exists()) {
                throw ValidationException::withMessages([
                    'organization_id' => 'That organization no longer exists. Reload the page and choose again.',
                ]);
            }

            return $organizationId;
        }

        $organizationId = (int) session('current_organization_id');

        if (! $organizationId) {
            throw ValidationException::withMessages([
                'name' => 'Select an organization before saving an ad — an ad belongs to the organization it was made for.',
            ]);
        }

        return $organizationId;
    }
}
