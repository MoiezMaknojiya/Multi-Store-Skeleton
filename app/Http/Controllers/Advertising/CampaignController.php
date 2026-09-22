<?php

namespace App\Http\Controllers\Advertising;

use App\Http\Controllers\Concerns\ConfirmsPassword;
use App\Http\Controllers\Concerns\HandlesCrudData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Advertising\CampaignRequest;
use App\Models\ActivityLog;
use App\Models\Campaign;
use App\Models\Media;
use App\Models\Screen;
use App\Services\MediaStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Network advertising: the platform's own content, sold to a brand and carried by
 * the shops that agreed to it.
 *
 * Every route here is behind `campaign-manage`, which only a super admin holds and
 * which is NOT a grantable permission row — a campaign has no store, so a store user
 * holding it would see every brand's contract across the whole network. That is the
 * one wall this app does not break.
 */
class CampaignController extends Controller
{
    use ConfirmsPassword, HandlesCrudData;

    public function __construct(private readonly MediaStorage $storage) {}

    public function index(): View
    {
        return view('campaigns.index', [
            'breakEverySeconds' => Campaign::breakEverySeconds(),
            'maxBreakSeconds' => Campaign::MAX_BREAK_SECONDS,
        ]);
    }

    /** Paginated, searchable campaigns, with the screens each one is pointed at. */
    public function data(Request $request): JsonResponse
    {
        $query = Campaign::query()
            ->with(['screens:id,name,store_id', 'screens.store:id,name'])
            ->withCount('screens')
            ->orderByDesc('created_at');

        return $this->paginatedResponse($request, $query, ['name', 'advertiser_name'], 'campaigns');
    }

    /**
     * Every screen a campaign could be pointed at, grouped by shop.
     *
     * Screens that have not been cleared for advertising come back too, flagged —
     * a greyed row that says "this shop has not agreed" is far more use than a screen
     * that silently is not in the list at all.
     */
    public function screens(): JsonResponse
    {
        $screens = Screen::with('store:id,name,accepts_network_ads')
            ->orderBy('store_id')
            ->orderBy('name')
            ->get(['id', 'name', 'store_id', 'accepts_network_ads']);

        $booked = $this->bookedSecondsPerScreen();

        return response()->json([
            'screens' => $screens->map(fn (Screen $screen) => [
                'id' => $screen->id,
                'name' => $screen->name,
                'store_id' => $screen->store_id,
                'store_name' => $screen->store?->name,
                'store_accepts' => (bool) $screen->store?->accepts_network_ads,
                'screen_accepts' => $screen->accepts_network_ads,
                'carries_ads' => $screen->accepts_network_ads && (bool) $screen->store?->accepts_network_ads,
                // How much of this screen's break is already sold. Shown while
                // choosing, so overselling is noticed before a shop ends up with a
                // three-minute advert break — and so the seconds that would not fit
                // are visible rather than silently dropped at the television.
                'booked_seconds' => (int) ($booked[$screen->id] ?? 0),
            ])->all(),
            'max_break_seconds' => Campaign::MAX_BREAK_SECONDS,
        ]);
    }

    /**
     * Seconds already sold on each screen, across every switched-on campaign.
     *
     * One query for the whole list rather than one per row. A video counts for its
     * own length and an image for its typed seconds — the same rule the break itself
     * uses, expressed in SQL.
     *
     * @return Collection<int, int|string> seconds by screen id — MySQL hands a SUM back as a string, hence the cast where it is read
     */
    private function bookedSecondsPerScreen(): Collection
    {
        return DB::table('campaign_screen')
            ->join('campaigns', 'campaigns.id', '=', 'campaign_screen.campaign_id')
            ->where('campaigns.is_active', true)
            ->groupBy('campaign_screen.screen_id')
            ->selectRaw('campaign_screen.screen_id as screen_id')
            ->selectRaw('SUM(COALESCE(NULLIF(campaigns.media_duration_seconds, 0), campaigns.duration_seconds)) as seconds')
            ->pluck('seconds', 'screen_id');
    }

    public function store(CampaignRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $campaign = DB::transaction(function () use ($request, $validated) {
            $file = $this->storage->storeCampaignFile($request->file('file'), $validated);

            $campaign = Campaign::create([
                ...$this->attributes($validated),
                ...$file,
                // A video's own length decides how long the break runs; the typed
                // seconds only govern an image.
                'media_duration_seconds' => $file['duration_seconds'],
                // A video's form sends no typed seconds; its measured length stands in.
                'duration_seconds' => $validated['duration_seconds'] ?? $file['duration_seconds'] ?? 15,
                'created_by' => auth()->id(),
            ]);

            $campaign->screens()->sync($validated['screen_ids']);

            return $campaign;
        });

        ActivityLog::record('campaign.created', $campaign, "Created campaign {$campaign->name}");

        return response()->json([
            'message' => 'Campaign created successfully',
            'campaign' => $campaign->load('screens:id,name'),
        ]);
    }

    /** Rename, re-time, re-target — and optionally swap the advert itself. */
    public function update(CampaignRequest $request, Campaign $campaign): JsonResponse
    {
        $validated = $request->validated();

        DB::transaction(function () use ($request, $validated, $campaign) {
            $attributes = $this->attributes($validated);

            if ($request->hasFile('file')) {
                $old = [$campaign->disk, $campaign->path, $campaign->thumbnail_path];
                $file = $this->storage->storeCampaignFile($request->file('file'), $validated);
                $attributes = [
                    ...$attributes,
                    ...$file,
                    'media_duration_seconds' => $file['duration_seconds'],
                    'duration_seconds' => $validated['duration_seconds'] ?? $file['duration_seconds'] ?? $campaign->duration_seconds,
                ];
            } elseif ($campaign->type !== Media::TYPE_VIDEO) {
                // Retimed without a new file. Only an image has seconds to change — a
                // video runs to its own length and its form has no such field. (Before
                // this, a new number typed here was silently dropped.)
                $attributes['duration_seconds'] = $validated['duration_seconds'];
            }

            $campaign->update($attributes);
            $campaign->screens()->sync($validated['screen_ids']);

            // The replaced file goes only once the row is safely pointing at the new
            // one — after the commit: a stale file on disk is harmless, a row pointing
            // at a deleted file is a black rectangle on somebody's wall.
            if (isset($old)) {
                DB::afterCommit(fn () => $this->storage->deleteFiles(...$old));
            }
        });

        ActivityLog::record('campaign.updated', $campaign, "Updated campaign {$campaign->name}");

        return response()->json([
            'message' => 'Campaign updated successfully',
            'campaign' => $campaign->fresh()->load('screens:id,name'),
        ]);
    }

    public function destroy(Request $request, Campaign $campaign): JsonResponse
    {
        $this->confirmPassword($request);

        $name = $campaign->name;

        DB::transaction(function () use ($campaign) {
            $campaign->screens()->detach();
            $campaign->delete();
            // The files only once the row is really gone: a rolled-back delete keeps a campaign that plays.
            DB::afterCommit(fn () => $this->storage->deleteFiles($campaign->disk, $campaign->path, $campaign->thumbnail_path));
        });

        ActivityLog::record('campaign.deleted', null, "Deleted campaign {$name}");

        return response()->json(['message' => 'Campaign deleted successfully']);
    }

    /**
     * The columns a campaign keeps about itself, as opposed to about its file.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function attributes(array $validated): array
    {
        return [
            'name' => $validated['name'],
            'advertiser_name' => $validated['advertiser_name'] ?? null,
            'starts_on' => $validated['starts_on'] ?? null,
            'ends_on' => $validated['ends_on'] ?? null,
            'start_time' => $validated['start_time'] ?? null,
            'end_time' => $validated['end_time'] ?? null,
            'is_active' => $validated['is_active'] ?? false,
        ];
    }
}
