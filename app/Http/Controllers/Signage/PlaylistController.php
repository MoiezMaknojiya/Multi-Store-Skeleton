<?php

namespace App\Http\Controllers\Signage;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Daypart;
use App\Models\Media;
use App\Models\PlaylistItem;
use App\Models\ScheduleRule;
use App\Models\Screen;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A screen's ordered playlist.
 *
 * The whole list is written in one request rather than patching positions one at
 * a time: reordering, retiming, adding and removing all end up as the same call,
 * it is atomic, and the client never has to keep server-side positions in sync.
 */
class PlaylistController extends Controller
{
    /** A sane ceiling — a playlist longer than this is a mistake, not a use case. */
    private const MAX_ITEMS = 200;

    /** Same reasoning for one item's schedules: a handful expresses everything a
     *  shop actually means, and a hundred is a runaway client. */
    private const MAX_RULES = 10;

    /** The current playlist, with just enough of each file to render a row. */
    public function index(Screen $screen): JsonResponse
    {
        $screen = Screen::visibleTo(auth()->user())->findOrFail($screen->id);

        return response()->json([
            'items' => $this->itemsPayload($screen),
            'version' => $screen->playlistFingerprint(),
        ]);
    }

    /**
     * The media this screen is allowed to play: its own store's library.
     *
     * Its own endpoint, gated by the playlist permission, so someone who may
     * change playlists does not also need media-view — a permission is meant to
     * be enough for its own job.
     */
    public function availableMedia(Request $request, Screen $screen): JsonResponse
    {
        $screen = Screen::visibleTo(auth()->user())->findOrFail($screen->id);

        // Checked rather than cast: ?search[]=x arrives as an array, and casting one to a string is
        // a 500. This endpoint is read by a picker that always sends a word, so a bad shape is a 422.
        $validated = $request->validate(['search' => ['nullable', 'string', 'max:255']]);
        $search = trim((string) ($validated['search'] ?? ''));

        // Never an Ad Builder page taken off the screens (unpublished): nobody picks one until it is published again.
        $media = Media::where('store_id', $screen->store_id)
            ->withoutDrafts()
            ->when($search !== '', fn (Builder $q) => $q->where('title', 'like', "%{$search}%"))
            ->orderByDesc('created_at')
            ->limit(100)
            ->get(['id', 'title', 'type', 'duration_seconds', 'orientation', 'disk', 'path', 'thumbnail_path']);

        return response()->json([
            'media' => $media->map(fn (Media $item) => [
                'id' => $item->id,
                'title' => $item->title,
                'type' => $item->type,
                'orientation' => $item->orientation,
                'duration_seconds' => $item->duration_seconds,
                'thumbnail_url' => $item->thumbnail_url,
            ])->all(),
        ]);
    }

    /**
     * Every channel this screen could carry: the platform's, offered to every shop, and
     * the screen's own store's channels — never another store's (Channel::availableTo).
     *
     * Gated by the playlist permission, like the media picker above, and described
     * against the screen's own today: "3 ads running" has to mean the three this
     * television would actually be sent.
     */
    public function availableChannels(Screen $screen): JsonResponse
    {
        $screen = Screen::visibleTo(auth()->user())->findOrFail($screen->id);
        $today = $screen->localTime();

        $channels = Channel::availableTo($screen)->with('ads')->orderBy('name')->get();

        return response()->json([
            'channels' => $channels->map(fn (Channel $channel) => [
                'id' => $channel->id,
                // The store's own channel, told apart in the box from the platform's.
                'is_store_channel' => $channel->store_id !== null,
                ...$this->channelSummary($channel, $today),
                // "Show ads" in the box: exactly what adding this channel would play.
                'ads' => $channel->runningAdsOn($today)->map(fn (ChannelAd $ad) => [
                    'id' => $ad->id,
                    'title' => $ad->title,
                    'type' => $ad->type,
                    'orientation' => $ad->orientation,
                    'thumbnail_url' => $ad->thumbnail_url,
                    'play_seconds' => $ad->play_seconds,
                    'ends_on' => $ad->ends_on?->toDateString(),
                ])->all(),
            ])->all(),
        ]);
    }

    /** Replace the playlist with exactly what was sent, in the order it was sent. */
    public function update(Request $request, Screen $screen): JsonResponse
    {
        $screen = Screen::visibleTo(auth()->user())->findOrFail($screen->id);

        $validated = $request->validate([
            'items' => ['present', 'array', 'max:'.self::MAX_ITEMS],
            // A line is a file OR a channel — exactly one of the two (checked below).
            // min:1 because an id is never zero, and a posted 0 would otherwise slip past
            // the checks below (which read it as "no id") and die on the foreign key.
            'items.*.media_id' => ['nullable', 'integer', 'min:1', 'required_without:items.*.channel_id'],
            'items.*.channel_id' => ['nullable', 'integer', 'min:1'],
            // A file needs a length; a channel line lasts as long as its ads do.
            'items.*.duration_seconds' => ['nullable', 'integer', 'min:1', 'max:86400', 'required_with:items.*.media_id'],
            // What the client had on screen when it started editing.
            'version' => ['required', 'string'],

            // Each item's schedule travels WITH the save rather than through an
            // endpoint of its own. The save replaces the whole list, so rules stored
            // separately would be deleted by the cascade on every ordinary reorder —
            // and this way the editor stages them and one "Save changes" commits the
            // lot, atomically.
            ...$this->ruleRules(),
        ], [
            'items.max' => 'A playlist cannot hold more than '.self::MAX_ITEMS.' items.',
            'items.*.media_id.required_without' => 'Each line of the playlist needs a file or a channel.',
            'items.*.duration_seconds.required_with' => 'Each file on the playlist needs a duration.',
            ...$this->ruleMessages(),
        ]);

        $items = $validated['items'];
        $this->assertEachLineIsAFileOrAChannel($items);
        $this->assertMediaBelongsToTheSameStore($screen, $items);
        $this->assertChannelsAreAvailable($screen, $items);
        $this->assertDaypartsBelongToTheSameStore($screen, $items);

        // A save replaces the WHOLE list, so a client working from a stale copy
        // would quietly wipe out whatever changed in the meantime — a colleague
        // adds a poster, you retime one item, and their poster is gone with no
        // sign of it. Refuse instead, and say so.
        if ($validated['version'] !== $screen->playlistFingerprint()) {
            return response()->json([
                'message' => 'Someone else changed this playlist while you were editing. Reload the page to see their changes, then make yours again.',
            ], 409);
        }

        DB::transaction(fn () => $this->writeItems($screen, $items));

        ActivityLog::record(
            'screen.playlist_updated',
            $screen,
            'Updated the playlist for screen '.$screen->name.' ('.count($items).' items)'
        );

        // The new version travels back with the save. Without it this page would
        // still be holding the version it loaded with and would collide with its
        // own previous save the moment it was used twice.
        return response()->json([
            'message' => 'Playlist saved',
            'items' => $this->itemsPayload($screen->fresh()),
            'version' => $screen->fresh()->playlistFingerprint(),
        ]);
    }

    /**
     * Copy this whole playlist — items, durations and schedules — onto other screens.
     *
     * Deliberately the WHOLE playlist rather than one item's schedule: it is one
     * thing to explain, one thing to undo, and it is what a shop actually wants when
     * three televisions are meant to show the same thing. The target's playlist is
     * replaced, so the panel asks first and says exactly what it is about to wipe —
     * copyTargets() below is what fills that confirmation in.
     */
    public function copy(Request $request, Screen $screen): JsonResponse
    {
        $screen = Screen::visibleTo(auth()->user())->findOrFail($screen->id);

        $validated = $request->validate([
            'target_screen_ids' => ['required', 'array', 'min:1'],
            'target_screen_ids.*' => ['integer', 'min:1'],
        ], [
            'target_screen_ids.required' => 'Choose at least one screen to copy to.',
        ]);

        $targets = Screen::visibleTo(auth()->user())
            ->where('store_id', $screen->store_id)
            ->whereIn('id', $validated['target_screen_ids'])
            ->where('id', '!=', $screen->id)
            ->get();

        if ($targets->isEmpty()) {
            throw ValidationException::withMessages([
                'target_screen_ids' => 'None of those screens are in this store.',
            ]);
        }

        // Read the source once, then write it to each target. Everything in one
        // transaction: half the televisions carrying the new playlist and half the
        // old one is worse than none of them changing.
        $items = $this->itemsForCopy($screen);

        DB::transaction(function () use ($targets, $items) {
            foreach ($targets as $target) {
                $this->writeItems($target, $items);
            }
        });

        ActivityLog::record(
            'screen.playlist_copied',
            $screen,
            'Copied the playlist from screen '.$screen->name.' to '.$targets->pluck('name')->implode(', ')
        );

        return response()->json([
            'message' => 'Playlist copied to '.$targets->count().' screen'.($targets->count() === 1 ? '' : 's'),
            'screens' => $targets->pluck('name')->all(),
        ]);
    }

    /**
     * The other screens this playlist could be copied to, and what each would lose.
     *
     * The count is the point: "replace" is destructive, so the person pressing it
     * should see that Window TV is about to lose four items before they press it,
     * not after.
     */
    public function copyTargets(Screen $screen): JsonResponse
    {
        $screen = Screen::visibleTo(auth()->user())->findOrFail($screen->id);

        $targets = Screen::visibleTo(auth()->user())
            ->where('store_id', $screen->store_id)
            ->where('id', '!=', $screen->id)
            ->withCount('playlistItems')
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'screens' => $targets->map(fn (Screen $target) => [
                'id' => $target->id,
                'name' => $target->name,
                'playlist_items_count' => $target->playlist_items_count,
            ])->all(),
        ]);
    }

    /**
     * When one rule would actually put its item on screen over the next week.
     *
     * Computed here, never in the browser, and through the very same coversDay()
     * the television is answered with — a preview built from a second implementation
     * would drift away from the truth and be worse than no preview at all.
     */
    public function preview(Request $request, Screen $screen): JsonResponse
    {
        $screen = Screen::visibleTo(auth()->user())->findOrFail($screen->id);

        // The same per-rule rules the PUT uses, re-keyed from `items.*.rules` to `rules`, so the
        // preview can never be handed a shape the save would refuse — and, in particular, so a
        // "rule" that is not even an array is a 422 here instead of reaching the mapper below and
        // dying in it.
        $rekey = fn (string $path) => str_replace('items.*.rules', 'rules', $path);
        $perRule = collect($this->ruleRules())->mapWithKeys(fn (array $rules, string $key) => [
            $rekey($key) => array_map(fn (string|object $rule) => is_string($rule) ? $rekey($rule) : $rule, $rules),
        ])->all();

        $validated = $request->validate([
            ...$perRule,
            'rules' => ['present', 'array', 'max:'.self::MAX_RULES],
            'rules.*' => ['array'],
            'days' => ['nullable', 'integer', 'min:1', 'max:60'],
        ]);

        // From the screen's own today, not the server's — a preview shown to somebody
        // setting up a television in another timezone has to agree with what that
        // television will actually do.
        $from = $screen->localTime();
        $days = $validated['days'] ?? 7;

        $occurrences = collect($validated['rules'])
            ->map(function (array $posted) use ($screen, $from) {
                $rule = new ScheduleRule($this->ruleAttributes($posted));

                // "Every 2 weeks" has to be every-2-weeks FROM something. A saved rule
                // with no start date anchors on its creation date, so the preview has
                // to anchor on today or it would show a different answer from the one
                // saving it a moment later would produce.
                $rule->created_at = $from;

                if ($rule->daypart_id !== null
                    && ! Daypart::where('id', $rule->daypart_id)->where('store_id', $screen->store_id)->exists()) {
                    throw ValidationException::withMessages([
                        'rules' => 'Those opening hours belong to a different store.',
                    ]);
                }

                return $rule;
            })
            // The item plays if ANY rule says yes, so the preview is the union of
            // them all — showing each rule's answer separately would leave the reader
            // to do the merging in their head.
            ->flatMap(fn (ScheduleRule $rule) => $rule->occurrences($from, $days))
            ->unique(fn (array $slot) => $slot['date'].$slot['start'].$slot['end'])
            ->sortBy([['date', 'asc'], ['start', 'asc']])
            ->values()
            ->all();

        return response()->json(['occurrences' => $occurrences]);
    }

    /**
     * A screen can only play its own store's files. Without this, a client could
     * post any media id it liked and a shop would end up showing another shop's
     * content — the store wall applied to the one place it is easy to forget.
     */
    private function assertMediaBelongsToTheSameStore(Screen $screen, array $items): void
    {
        // Null means "this line is a channel", nothing else — so the filter asks for
        // exactly that, rather than dropping every falsy value with it.
        $ids = collect($items)->pluck('media_id')->reject(fn (int|string|null $id) => $id === null)->unique();

        if ($ids->isEmpty()) {
            return;
        }

        $allowed = Media::where('store_id', $screen->store_id)->whereIn('id', $ids)->count();

        if ($allowed !== $ids->count()) {
            throw ValidationException::withMessages([
                'items' => 'One of those files is not in this store\'s library.',
            ]);
        }
    }

    /**
     * Each line is a file or a channel — never both, never neither.
     *
     * "Neither" is already refused by the validation rules. "Both" is caught here: a
     * line claiming to be two things would play one of them and silently drop the
     * other, depending on which the code happened to look at first.
     */
    private function assertEachLineIsAFileOrAChannel(array $items): void
    {
        foreach ($items as $item) {
            if (filled($item['media_id'] ?? null) && filled($item['channel_id'] ?? null)) {
                throw ValidationException::withMessages([
                    'items' => 'A line of the playlist is a file or a channel, never both.',
                ]);
            }
        }
    }

    /**
     * A channel line may carry the platform's channel or the screen's own store's — the
     * store wall again, since a channel id is a number any client can post. It also catches
     * a channel deleted while this page was open, which would otherwise fail on the foreign
     * key with an error nobody could read.
     */
    private function assertChannelsAreAvailable(Screen $screen, array $items): void
    {
        $ids = collect($items)->pluck('channel_id')->reject(fn (int|string|null $id) => $id === null)->unique();

        if ($ids->isEmpty()) {
            return;
        }

        if (Channel::availableTo($screen)->whereIn('id', $ids)->count() !== $ids->count()) {
            throw ValidationException::withMessages([
                'items' => 'One of those channels has been deleted, or is not offered to this store. Reload the page to see the current list.',
            ]);
        }
    }

    /**
     * A daypart on a rule is a foreign key a client can post any number into, so it
     * goes through the same wall the media does.
     */
    private function assertDaypartsBelongToTheSameStore(Screen $screen, array $items): void
    {
        $ids = collect($items)
            ->flatMap(fn (array $item) => $item['rules'] ?? [])
            ->pluck('daypart_id')
            ->filter()
            ->unique();

        if ($ids->isEmpty()) {
            return;
        }

        $allowed = Daypart::where('store_id', $screen->store_id)->whereIn('id', $ids)->count();

        if ($allowed !== $ids->count()) {
            throw ValidationException::withMessages([
                'items' => 'Those opening hours belong to a different store.',
            ]);
        }
    }

    /** Write a whole playlist, rules and all, replacing whatever was there. */
    private function writeItems(Screen $screen, array $items): void
    {
        // The items go first; their rules go with them through the foreign key.
        $screen->playlistItems()->delete();

        // Sorted by KEY before anything reads their order, and this is not defensive
        // tidying: a validated array is rebuilt rule by rule, not row by row, so a line
        // that has no `media_id` at all — a channel — is inserted into the result AFTER
        // every line that has one. The keys are still the positions the shop arranged;
        // the array's own order is not. Trusting it saved "channel first, poster second"
        // to a television the other way round.
        ksort($items);

        foreach (array_values($items) as $position => $item) {
            $isChannel = filled($item['channel_id'] ?? null);

            $created = PlaylistItem::create([
                'screen_id' => $screen->id,
                'media_id' => $isChannel ? null : $item['media_id'],
                'channel_id' => $isChannel ? $item['channel_id'] : null,
                'position' => $position,
                // A channel line lasts as long as its ads do; only a file keeps a length.
                'duration_seconds' => $isChannel ? null : $item['duration_seconds'],
            ]);

            // A line's rules are rebuilt the same way — one missing a key that another has can
            // land after it — and their order is the order they are shown back in.
            $rules = $item['rules'] ?? [];
            ksort($rules);

            foreach (array_values($rules) as $index => $rule) {
                $created->scheduleRules()->create([
                    ...$this->ruleAttributes($rule),
                    'position' => $index,
                ]);
            }
        }
    }

    /** This screen's playlist in the shape writeItems() takes, for copying. */
    private function itemsForCopy(Screen $screen): array
    {
        return $screen->playlistItems()->with('scheduleRules')->get()
            ->map(fn (PlaylistItem $item) => [
                'media_id' => $item->media_id,
                'channel_id' => $item->channel_id,
                'duration_seconds' => $item->duration_seconds,
                'rules' => $item->scheduleRules->map(fn (ScheduleRule $rule) => [
                    'daypart_id' => $rule->daypart_id,
                    'starts_on' => $rule->starts_on?->toDateString(),
                    'ends_on' => $rule->ends_on?->toDateString(),
                    'recurrence_type' => $rule->recurrence_type,
                    'recurrence_interval' => $rule->recurrence_interval,
                    'recurrence_weekdays' => $rule->recurrence_weekdays,
                    'recurrence_monthday' => $rule->recurrence_monthday,
                    'recurrence_ordinal' => $rule->recurrence_ordinal,
                    'recurrence_weekday' => $rule->recurrence_weekday,
                    'recurrence_until' => $rule->recurrence_until?->toDateString(),
                ])->all(),
            ])->all();
    }

    /**
     * One posted rule, normalised.
     *
     * Blank strings come out of empty date inputs and would be stored as "" rather
     * than null; the fields belonging to other repeat types are dropped rather than
     * kept, so a rule that was "monthly on the 21st" and is now "every Friday" does
     * not quietly carry a 21 around with it.
     *
     * @return array<string, mixed>
     */
    private function ruleAttributes(array $rule): array
    {
        $type = blank($rule['recurrence_type'] ?? null) ? null : $rule['recurrence_type'];
        $blank = fn (string $key) => blank($rule[$key] ?? null) ? null : $rule[$key];

        return [
            'daypart_id' => $blank('daypart_id'),
            'starts_on' => $blank('starts_on'),
            'ends_on' => $blank('ends_on'),
            'recurrence_type' => $type,
            'recurrence_interval' => max(1, (int) ($rule['recurrence_interval'] ?? 1)),
            'recurrence_weekdays' => $type === ScheduleRule::WEEKLY
                ? array_values(array_map('intval', $rule['recurrence_weekdays'] ?? []))
                : null,
            'recurrence_monthday' => $type === ScheduleRule::MONTHLY_DAY ? $blank('recurrence_monthday') : null,
            'recurrence_ordinal' => $type === ScheduleRule::MONTHLY_WEEKDAY ? $blank('recurrence_ordinal') : null,
            'recurrence_weekday' => $type === ScheduleRule::MONTHLY_WEEKDAY ? $blank('recurrence_weekday') : null,
            'recurrence_until' => $type === null ? null : $blank('recurrence_until'),
        ];
    }

    /**
     * The rules for the rules.
     *
     * Each repeat type needs different fields filled in, so `required_if` does the
     * work rather than a wall of manual checks — Laravel resolves the wildcards to
     * the same row, so "weekly needs weekdays" is stated once for every item.
     *
     * @return array<string, array<int, mixed>>
     */
    private function ruleRules(): array
    {
        $type = 'items.*.rules.*.recurrence_type';

        return [
            'items.*.rules' => ['array', 'max:'.self::MAX_RULES],
            // min:1 deliberately: a posted 0 reads as "no id" to filled()/->filter() and would pass the
            // same-store check below on its way to a foreign-key error.
            'items.*.rules.*.daypart_id' => ['nullable', 'integer', 'min:1'],
            'items.*.rules.*.starts_on' => ['nullable', 'date_format:Y-m-d'],
            'items.*.rules.*.ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:items.*.rules.*.starts_on'],
            $type => ['nullable', Rule::in(array_keys(ScheduleRule::TYPES))],
            'items.*.rules.*.recurrence_interval' => ['nullable', 'integer', 'min:1', 'max:52'],
            'items.*.rules.*.recurrence_weekdays' => ['array', 'max:7', 'required_if:'.$type.','.ScheduleRule::WEEKLY],
            'items.*.rules.*.recurrence_weekdays.*' => ['integer', 'between:1,7'],
            'items.*.rules.*.recurrence_monthday' => ['nullable', 'integer', 'between:1,31', 'required_if:'.$type.','.ScheduleRule::MONTHLY_DAY],
            'items.*.rules.*.recurrence_ordinal' => ['nullable', 'integer', Rule::in(array_keys(ScheduleRule::ORDINALS)), 'required_if:'.$type.','.ScheduleRule::MONTHLY_WEEKDAY],
            'items.*.rules.*.recurrence_weekday' => ['nullable', 'integer', 'between:1,7', 'required_if:'.$type.','.ScheduleRule::MONTHLY_WEEKDAY],
            // A repeat that ends before it starts would never fire, and a person who
            // typed that meant something else.
            'items.*.rules.*.recurrence_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:items.*.rules.*.starts_on'],
        ];
    }

    /** @return array<string, string> */
    private function ruleMessages(): array
    {
        return [
            'items.*.rules.max' => 'An item cannot have more than '.self::MAX_RULES.' schedules.',
            'items.*.rules.*.ends_on.after_or_equal' => 'The end date cannot be before the start date.',
            'items.*.rules.*.recurrence_until.after_or_equal' => 'The repeat cannot end before the schedule starts.',
            'items.*.rules.*.recurrence_weekdays.required_if' => 'Choose at least one day of the week.',
            'items.*.rules.*.recurrence_monthday.required_if' => 'Choose which day of the month.',
            'items.*.rules.*.recurrence_ordinal.required_if' => 'Choose which occurrence — first, second, and so on.',
            'items.*.rules.*.recurrence_weekday.required_if' => 'Choose which weekday.',
        ];
    }

    /**
     * What the panel shows for a channel: its name and picture, whether it is on the
     * air, and how much of it is running on this day.
     *
     * "Running" is paused-or-not, deliberately: a paused channel still shows the ads it
     * would play, with the pause said beside it, rather than looking empty.
     *
     * @return array<string, mixed>
     */
    private function channelSummary(Channel $channel, CarbonInterface $today): array
    {
        $running = $channel->runningAdsOn($today);

        return [
            'title' => $channel->name,
            'type' => 'channel',
            'thumbnail_url' => ($running->first() ?? $channel->ads->first())?->thumbnail_url,
            'channel_active' => $channel->is_active,
            'ads_count' => $running->count(),
            'pass_ads' => $channel->adsPerPassOf($running),
            'pass_seconds' => $channel->passSeconds($running),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function itemsPayload(Screen $screen): array
    {
        // Channel lines are described against the screen's own today — the calendar
        // the television itself is answered with.
        $today = $screen->localTime();

        return $screen->playlistItems()->with(['media.builderAd', 'channel.ads', 'scheduleRules'])->get()
            ->map(fn (PlaylistItem $item) => [
                'id' => $item->id,
                'media_id' => $item->media_id,
                'channel_id' => $item->channel_id,
                'position' => $item->position,
                'duration_seconds' => $item->duration_seconds,
                ...($item->channel !== null ? $this->channelSummary($item->channel, $today) : [
                    'title' => $item->media?->title,
                    'type' => $item->media?->type,
                    'thumbnail_url' => $item->media?->thumbnail_url,
                    // Both null means "always"; the player never sees an item whose
                    // window has closed, but the panel should say so.
                    'starts_at' => $item->media?->starts_at?->toIso8601String(),
                    'expires_at' => $item->media?->expires_at?->toIso8601String(),
                    // An Ad Builder page taken off the screens (unpublished) keeps its place and plays again once it
                    // is published — the panel says why it is not playing meanwhile.
                    'is_draft' => $item->media?->isDraft() ?? false,
                ]),
                'rules' => $item->scheduleRules->map(fn (ScheduleRule $rule) => [
                    'daypart_id' => $rule->daypart_id,
                    'starts_on' => $rule->starts_on?->toDateString(),
                    'ends_on' => $rule->ends_on?->toDateString(),
                    'recurrence_type' => $rule->recurrence_type,
                    'recurrence_interval' => $rule->recurrence_interval,
                    'recurrence_weekdays' => $rule->recurrence_weekdays ?? [],
                    'recurrence_monthday' => $rule->recurrence_monthday,
                    'recurrence_ordinal' => $rule->recurrence_ordinal,
                    'recurrence_weekday' => $rule->recurrence_weekday,
                    'recurrence_until' => $rule->recurrence_until?->toDateString(),
                ])->all(),
            ])->all();
    }
}
