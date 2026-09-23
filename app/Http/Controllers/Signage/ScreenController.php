<?php

namespace App\Http\Controllers\Signage;

use App\Http\Controllers\Concerns\HandlesCrudData;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\BuilderAd;
use App\Models\Daypart;
use App\Models\Media;
use App\Models\ScheduleRule;
use App\Models\Screen;
use App\Models\Store;
use App\Services\DevicePairing;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ScreenController extends Controller
{
    use HandlesCrudData;

    /**
     * Render the screens page.
     *
     * The option lists travel with the page rather than through their own AJAX
     * endpoints: they are small, fixed, and needed the instant a modal opens. The
     * page's own permission is what gates them, which keeps `screen-update`
     * self-sufficient without a second endpoint to gate.
     */
    public function index(): View
    {
        return view('screens.index', [
            'orientations' => Screen::ORIENTATIONS,
            'timezones' => $this->timezoneOptions(),
            // Only the advertising panel uses this, and only a super admin inside an
            // impersonated session ever sees that panel.
            'storeAcceptsAds' => (bool) Store::find(session('current_store_id'))?->accepts_network_ads,
        ]);
    }

    /** Return paginated, searchable screens as JSON, scoped to the current store. */
    public function data(Request $request): JsonResponse
    {
        // The count rides along so the listing can say which screens actually
        // have something to play, without a query per row.
        $query = Screen::visibleTo(auth()->user())->withCount('playlistItems')->orderByDesc('created_at');

        // Searchable by everything the row actually shows: the name, the device id
        // (the listing prints its last block, and a LIKE finds that inside the whole
        // uuid), and the pairing date.
        return $this->paginatedResponse(
            $request,
            $query,
            ['name', 'device_uuid'],
            'screens',
            ['*'],
            null,
            function (Builder $q, string $search) {
                // Stored as "2026-09-07 14:08:48", so a typed 2026-09-07 — or just
                // 2026-09 — matches straight away.
                $q->orWhere('paired_at', 'like', "%{$search}%");

                // But the panel prints the date in the READER's own locale, so what
                // somebody sees and copies is "9/7/2026". Match that too, or the
                // search fails on the very text the screen is showing them.
                foreach ($this->datesMeaning($search) as $date) {
                    $q->orWhereDate('paired_at', $date);
                }
            }
        );
    }

    /**
     * The files this screen could hold as its holding picture.
     *
     * Its own endpoint gated by `screen-update` alone, so somebody who may edit a
     * screen does not also need `media-view` — a permission has to be enough for
     * its own job. Scoped by the SCREEN's store, not the session's, so it stays
     * right for a global user with no store context.
     */
    public function mediaOptions(Request $request, Screen $screen): JsonResponse
    {
        $screen = Screen::visibleTo(auth()->user())->findOrFail($screen->id);

        // Checked rather than cast: ?search[]=x arrives as an array, and casting one to a string is
        // a 500. This endpoint is read by a picker that always sends a word, so a bad shape is a 422.
        $validated = $request->validate(['search' => ['nullable', 'string', 'max:255']]);
        $search = trim((string) ($validated['search'] ?? ''));

        // Never an Ad Builder page taken off the screens (unpublished), nor one kept for channels only: a
        // holding picture is this screen playing the ad by itself, exactly what that tick refuses.
        $media = Media::where('store_id', $screen->store_id)
            ->withoutDrafts()
            ->withoutChannelOnly()
            ->when($search !== '', fn (Builder $q) => $q->where('title', 'like', "%{$search}%"))
            ->orderBy('title')
            ->limit(100)
            // The orientation, so the list can say which files are the other way round from the screen.
            ->get(['id', 'title', 'type', 'orientation']);

        return response()->json(['media' => $media]);
    }

    /** The playlist page for one screen. */
    public function show(Screen $screen): View
    {
        // Route middleware is not enough: the target has to be inside the store the
        // actor is working in, or it does not exist for them (404, never 403).
        $screen = Screen::visibleTo(auth()->user())->findOrFail($screen->id);

        return view('screens.show', [
            'screen' => $screen,
            'orientations' => Screen::ORIENTATIONS,
            // The schedule editor picks a window from these; "New daypart" on the
            // Dayparts page is the way to add one.
            'dayparts' => $this->daypartOptions($screen),
            'weekdays' => Daypart::WEEKDAYS,
            'recurrenceTypes' => ScheduleRule::TYPES,
            'ordinals' => ScheduleRule::ORDINALS,
        ]);
    }

    /**
     * Adopt a television, or move an existing screen onto a new one.
     *
     * Two modes through one endpoint because they are the same handshake: the code
     * on the TV is exchanged for that screen's token. "new" makes the screen first;
     * "replace" keeps the screen the shop already set up — its name, orientation
     * and playlist — and only rotates which device answers for it.
     */
    public function pair(Request $request, DevicePairing $pairing): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'size:6'],
            'mode' => ['required', 'in:new,replace'],
            'name' => ['required_if:mode,new', 'nullable', 'string', 'max:255'],
            'orientation' => ['required_if:mode,new', 'nullable', Rule::in(array_keys(Screen::ORIENTATIONS))],
            'screen_id' => ['required_if:mode,replace', 'nullable', 'integer', 'min:1'],
        ], [
            'code.size' => 'A pairing code is exactly 6 characters.',
            'name.required_if' => 'Give the screen a name.',
            'orientation.required_if' => 'Choose how the screen is mounted.',
            'screen_id.required_if' => 'Choose which screen this device replaces.',
        ]);

        return $validated['mode'] === 'replace'
            ? $this->replaceDevice($validated, $pairing)
            : $this->pairNewScreen($validated, $pairing);
    }

    /** A screen the shop has not set up yet: make it, then hand it the token. */
    private function pairNewScreen(array $validated, DevicePairing $pairing): JsonResponse
    {
        $storeId = $this->currentStoreId();

        // One transaction, because a code that turns out to be dead must leave no
        // trace. Without it a bad code would still create the screen and the shop
        // would collect ghost rows every time somebody mistyped.
        $screen = DB::transaction(function () use ($validated, $storeId, $pairing) {
            $screen = Screen::create([
                'store_id' => $storeId,
                'name' => $validated['name'],
                'orientation' => $validated['orientation'],
                'created_by' => auth()->id(),
            ]);

            if (! $pairing->claim($validated['code'], $screen)) {
                throw ValidationException::withMessages([
                    'code' => 'That code is not valid any more. Check the screen and try the code it shows now.',
                ]);
            }

            return $screen;
        });

        ActivityLog::record('screen.paired', $screen, "Paired screen {$screen->name}");

        return response()->json(['message' => 'Screen paired successfully', 'screen' => $screen->fresh()]);
    }

    /** A screen that already exists: rotate its token onto the new device. */
    private function replaceDevice(array $validated, DevicePairing $pairing): JsonResponse
    {
        $screen = Screen::visibleTo(auth()->user())->findOrFail($validated['screen_id']);

        if (! $pairing->claim($validated['code'], $screen)) {
            throw ValidationException::withMessages([
                'code' => 'That code is not valid any more. Check the screen and try the code it shows now.',
            ]);
        }

        ActivityLog::record('screen.repaired', $screen, "Re-paired screen {$screen->name} with a new device");

        return response()->json(['message' => 'Device replaced successfully', 'screen' => $screen->fresh()]);
    }

    /** Rename a screen, change how it is mounted, its clock, or what it falls back to. */
    public function update(Request $request, Screen $screen): JsonResponse
    {
        $screen = Screen::visibleTo(auth()->user())->findOrFail($screen->id);

        // Only name and orientation are required. Everything the schedule added is
        // "sometimes": a caller that just renames a screen must not silently reset
        // its timezone, and a key that was never sent is not an instruction to clear
        // the value.
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'orientation' => ['required', Rule::in(array_keys(Screen::ORIENTATIONS))],
            // The clock this screen keeps — every schedule rule is read against it.
            // An IANA identifier, never an offset, so daylight saving looks after
            // itself.
            'timezone' => ['sometimes', 'required', 'string', 'max:64', Rule::in(timezone_identifiers_list())],
            // Null IS a real answer: no default media means a black screen when
            // nothing is due.
            'default_media_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ], [
            'timezone.in' => 'That is not a timezone this server knows.',
        ]);

        // A foreign key a client could post any number into, so it is looked up
        // through the store wall before it is trusted.
        $this->assertBelongsToSameStore($screen, $validated['default_media_id'] ?? null,
            'default_media_id', 'That file is not in this store\'s library.');
        $this->assertMayPlayByItself($validated['default_media_id'] ?? null);

        $screen->update($validated);

        ActivityLog::record('screen.updated', $screen, "Updated screen {$screen->name}");

        return response()->json([
            'message' => 'Screen updated successfully',
            'screen' => $screen->fresh(),
        ]);
    }

    /**
     * A foreign key posted by a client is a claim, not a fact.
     *
     * The store wall in the one place it is easiest to forget: without this, a screen could be made to hold
     * another shop's file as its default.
     */
    private function assertBelongsToSameStore(Screen $screen, ?int $id, string $field, string $message): void
    {
        if ($id === null) {
            return;
        }

        if (! Media::where('id', $id)->where('store_id', $screen->store_id)->exists()) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }

    /**
     * A holding picture is this screen playing a file by itself, so an Ad Builder page kept for channels
     * belongs here no more than on a playlist (owner's rule, 2026-09-22). The picker already leaves it out;
     * this is the wall behind it, for a foreign key posted by hand.
     */
    private function assertMayPlayByItself(?int $id): void
    {
        if ($id === null) {
            return;
        }

        $ad = BuilderAd::where('media_id', $id)->where('in_playlists', false)->first();

        if ($ad !== null) {
            throw ValidationException::withMessages([
                'default_media_id' => "The ad {$ad->name} is for channels only. Tick \"Show in playlists\" in the Ad Builder to put it on a screen.",
            ]);
        }
    }

    /**
     * The dayparts a rule on this screen may name: the live ones of the SCREEN's store — never another
     * store's, even for the platform team, who can see them all (the playlist would refuse one anyway) —
     * plus any retired one a rule here still uses, marked `retired`. A retired daypart keeps working for
     * the rules that had it; left out of this list, such a rule read "All day" on the page.
     *
     * The times go out as stored — 24-hour "HH:MM" — and the browser turns them into
     * AM/PM. Building the label here too would mean two places that decide how a clock
     * reads, and one of them would eventually drift.
     *
     * @return list<array{id: int, name: string, start_time: string, end_time: string, retired: bool}>
     */
    private function daypartOptions(Screen $screen): array
    {
        $used = ScheduleRule::whereIn('playlist_item_id', $screen->playlistItems()->select('id'))
            ->whereNotNull('daypart_id')
            ->select('daypart_id');

        return Daypart::visibleTo(auth()->user())
            ->where('store_id', $screen->store_id)
            ->where(fn (Builder $query) => $query->where('is_retired', false)->orWhereIn('id', $used))
            ->orderBy('name')
            ->get(['id', 'name', 'start_time', 'end_time', 'is_retired'])
            ->map(fn (Daypart $daypart) => [
                'id' => $daypart->id,
                'name' => $daypart->name,
                'start_time' => $daypart->start_time,
                'end_time' => $daypart->end_time,
                'retired' => $daypart->is_retired,
            ])->all();
    }

    /** Every timezone this server knows, so a screen can be told where it stands. */
    private function timezoneOptions(): array
    {
        return timezone_identifiers_list();
    }

    /**
     * Delete a screen. This is also how a device is revoked: the token goes with
     * the row, so the television gets 401 on its very next request and falls back
     * to showing a fresh pairing code.
     */
    public function destroy(Screen $screen): JsonResponse
    {
        $screen = Screen::visibleTo(auth()->user())->findOrFail($screen->id);
        $name = $screen->name;

        $screen->delete();

        ActivityLog::record('screen.deleted', null, "Deleted screen {$name}", storeId: $screen->store_id);

        return response()->json(['message' => 'Screen deleted successfully']);
    }

    /**
     * The dates a typed search term could mean.
     *
     * The panel prints a pairing date with the browser's own locale, so the string
     * a person is looking at — and will copy into the box — is "9/7/2026" here and
     * "7/9/2026" somewhere else. Both readings are returned rather than guessing:
     * a search box that quietly finds nothing is worse than one that finds a row
     * the reader did not have in mind, and they can see the date on the row.
     *
     * Round-tripped through the same format before being accepted, or PHP happily
     * reads "counter" as a date and every search starts matching rows at random.
     *
     * @return list<string>
     */
    private function datesMeaning(string $term): array
    {
        $term = trim($term);
        $dates = [];

        foreach (['Y-m-d', 'n/j/Y', 'j/n/Y', 'd-m-Y', 'd.m.Y'] as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $term);
            } catch (\Throwable) {
                continue;
            }

            if ($parsed && $parsed->format($format) === $term) {
                $dates[$parsed->toDateString()] = true;
            }
        }

        return array_keys($dates);
    }

    /** A screen belongs to a store, so pairing one needs a store context. */
    private function currentStoreId(): int
    {
        $storeId = (int) session('current_store_id');

        if (! $storeId) {
            throw ValidationException::withMessages([
                'code' => 'Select a store before pairing — a screen belongs to the store it is paired in.',
            ]);
        }

        return $storeId;
    }
}
