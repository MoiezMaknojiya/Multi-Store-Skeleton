<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\BuilderAd;
use App\Models\Campaign;
use App\Models\Channel;
use App\Models\Media;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Screen;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What the landing page says (owner, 2026-09-30: "user friendly banao puri site ko" — the dashboard was an empty
 * page): how the organization is doing and what needs doing, or — above the organizations — how the platform is. Every part is the
 * person's to see only with the permission its own page asks, and every link goes where that permission leads, so the
 * dashboard never offers what a click would refuse (the one exception is the platform's count of organizations, which every
 * account above the organizations has always been shown). Counts only: it reads no file and no design.
 */
class DashboardSummary
{
    /** An organization's storage this full is said on the dashboard. */
    private const STORAGE_WARNING_PERCENT = 90;

    /** How many of each list the dashboard names; the rest are counted in one line leading to where they all are. */
    private const LIST_LENGTH = 5;

    public function __construct(private readonly OrganizationStorage $quota, private readonly DiskGuard $disk) {}

    /**
     * One organization's dashboard, for a person working in it. `attention` is null for somebody who may look at neither the
     * screens nor the files (nothing they could be told about), and `activity` for somebody without the log — an empty
     * list means "all quiet", which is not the same thing.
     *
     * @return array{organization: string, cards: list<array<string, mixed>>, attention: list<array<string, mixed>>|null,
     *     steps: list<array<string, mixed>>, activity: list<array<string, mixed>>|null}
     */
    public function forOrganization(Organization $organization, User $user): array
    {
        $cards = [];
        $attention = [];
        $screens = collect();
        $mediaCount = null;

        if ($user->can('screen-view')) {
            $screens = Screen::where('organization_id', $organization->id)->withCount('playlistItems')->orderBy('name')
                ->get(['id', 'name', 'last_seen_at', 'organization_id']);
            $online = $screens->filter(fn (Screen $screen) => $screen->is_online)->count();

            $cards[] = [
                'key' => 'screens', 'label' => 'Screens', 'value' => $screens->count(),
                'detail' => $screens->isEmpty() ? 'None paired yet' : "{$online} online now",
                'href' => route('screens.view'),
            ];

            $offline = $screens->reject(fn (Screen $screen) => $screen->is_online);
            foreach ($offline->take(self::LIST_LENGTH) as $screen) {
                $attention[] = [
                    'key' => "screen-offline-{$screen->id}",
                    'tone' => 'warning',
                    'text' => "{$screen->name} is offline",
                    'detail' => $screen->last_seen_at ? 'Last seen '.$screen->last_seen_at->diffForHumans() : 'It has never connected',
                    'href' => route('screens.show', $screen),
                ];
            }
            $attention = [...$attention, ...$this->andMore('screen-offline-more', $offline->count(), 'warning',
                'more screens are offline', 'They are all on the Screens page', route('screens.view'))];

            $empty = $screens->where('playlist_items_count', 0);
            foreach ($empty->take(self::LIST_LENGTH) as $screen) {
                $attention[] = [
                    'key' => "screen-empty-{$screen->id}",
                    'tone' => 'info',
                    'text' => "{$screen->name} has nothing to play",
                    'detail' => 'Add files or a channel to its playlist',
                    'href' => route('screens.show', $screen),
                ];
            }
            $attention = [...$attention, ...$this->andMore('screen-empty-more', $empty->count(), 'info',
                'more screens have nothing to play', 'They are all on the Screens page', route('screens.view'))];
        }

        if ($user->can('media-view')) {
            // What the Media page lists: photographs and videos, not the Ad Builder's pages.
            $mediaCount = Media::where('organization_id', $organization->id)->withoutAdPages()->count();
            $storage = $this->quota->summary($organization->id);
            $percent = $storage && $storage['limit'] > 0 ? (int) floor($storage['used'] * 100 / $storage['limit']) : 0;

            $cards[] = [
                'key' => 'media', 'label' => 'Files', 'value' => $mediaCount,
                'detail' => $storage ? OrganizationStorage::inWords($storage['used']).' of '.OrganizationStorage::inWords($storage['limit']).' used' : null,
                'href' => route('media.view'),
            ];

            if ($storage && $percent >= self::STORAGE_WARNING_PERCENT) {
                $attention[] = [
                    'key' => 'storage',
                    'tone' => 'warning',
                    'text' => "Storage is {$percent}% full",
                    'detail' => 'Delete files you no longer use to make room',
                    'href' => route('media.view'),
                ];
            }
        }

        if ($user->can('ad-view')) {
            $ads = BuilderAd::where('organization_id', $organization->id)->selectRaw('count(*) as total, count(published_at) as published')->first();
            $published = (int) ($ads->published ?? 0);
            $drafts = (int) ($ads->total ?? 0) - $published;

            $cards[] = [
                'key' => 'ads', 'label' => 'Ads', 'value' => $published,
                'detail' => $drafts > 0 ? "published · {$drafts} ".Str::plural('draft', $drafts) : 'published',
                'href' => route('builder.index'),
            ];
        }

        if ($user->can('channel-view')) {
            $cards[] = [
                'key' => 'channels', 'label' => 'Channels', 'value' => Channel::where('organization_id', $organization->id)->count(),
                'detail' => 'made by this organization',
                'href' => route('channels.view'),
            ];
        }

        return [
            'organization' => $organization->name,
            'cards' => $cards,
            'attention' => $user->can('screen-view') || $user->can('media-view') ? $attention : null,
            'steps' => $this->gettingStarted($user, $screens, $mediaCount),
            'activity' => $user->can('activity-view')
                ? $this->recentActivity(ActivityLog::where('organization_id', $organization->id))
                : null,
        ];
    }

    /**
     * The platform's dashboard, for a person above the organizations; null parts as in forOrganization.
     *
     * @return array{cards: list<array<string, mixed>>, attention: list<array<string, mixed>>|null, activity: list<array<string, mixed>>|null}
     */
    public function forPlatform(User $user): array
    {
        $cards = [];
        $attention = [];

        $organizations = Organization::count();
        $cards[] = [
            'key' => 'organizations', 'label' => 'Organizations', 'value' => $organizations,
            'detail' => Organization::where('is_active', true)->count().' active',
            'href' => $user->can('organization-view') ? route('organizations.view') : null,
        ];

        if ($user->can('user-view')) {
            // The accounts the Users page lists to this person: the platform team is the super admins' business.
            $accounts = User::query()->when(! $user->isSuperAdmin(), fn (Builder $query) => $query->whereNotIn(
                'id', DB::table('organization_user')->where('organization_id', 0)->select('user_id')
            ));

            $cards[] = [
                'key' => 'users', 'label' => 'Accounts', 'value' => (clone $accounts)->count(),
                'detail' => $accounts->whereNull('email_verified_at')->count().' not yet confirmed',
                'href' => route('users.view'),
            ];
        }

        if ($user->can('screen-view')) {
            $online = Screen::online()->count();
            $cards[] = [
                'key' => 'screens', 'label' => 'Screens', 'value' => Screen::count(),
                'detail' => "{$online} online now",
                'href' => route('screens.view'),
            ];
        }

        if ($user->can('campaign-manage')) {
            $cards[] = [
                'key' => 'campaigns', 'label' => 'Campaigns', 'value' => Campaign::where('is_active', true)->count(),
                'detail' => 'switched on',
                'href' => route('campaigns.view'),
            ];
        }

        if ($user->isSuperAdmin()) {
            $free = $this->disk->freeBytes();

            if ($free !== null) {
                $cards[] = [
                    'key' => 'disk', 'label' => 'Free on the server', 'value' => DiskGuard::inWords($free),
                    'detail' => 'uploads pause below '.DiskGuard::inWords($this->disk->reserve()),
                    'href' => null,
                ];

                if ($this->disk->warning() > 0 && $free < $this->disk->warning()) {
                    $attention[] = [
                        'key' => 'disk',
                        'tone' => 'warning',
                        'text' => 'The server is running low on space',
                        'detail' => DiskGuard::inWords($free).' free',
                        'href' => null,
                    ];
                }
            }
        }

        if ($user->can('organization-view')) {
            $ownerId = Role::owner()?->id;
            $ownerless = Organization::whereDoesntHave('users', fn (Builder $users) => $users->where('organization_user.role_id', $ownerId));

            foreach ((clone $ownerless)->orderBy('name')->limit(self::LIST_LENGTH)->get(['id', 'name']) as $organization) {
                $attention[] = [
                    'key' => "organization-ownerless-{$organization->id}",
                    'tone' => 'warning',
                    'text' => "{$organization->name} has no Owner",
                    'detail' => 'Invite one from the Organizations page',
                    'href' => route('organizations.view'),
                ];
            }
            $attention = [...$attention, ...$this->andMore('organization-ownerless-more', $ownerless->count(), 'warning',
                'more organizations have no Owner', 'They are all on the Organizations page', route('organizations.view'))];
        }

        return [
            'cards' => $cards,
            'attention' => $user->can('organization-view') ? $attention : null,
            'activity' => $user->can('activity-view') ? $this->recentActivity(ActivityLog::query()) : null,
        ];
    }

    /**
     * The first three things a new organization does, each ticked once done, and each offered only to somebody who may do it —
     * which takes the page it happens on too (Add Screen is on the Screens page, behind screen-view). All done, the
     * list goes: an organization that runs needs no checklist.
     *
     * @param  Collection<int, Screen>  $screens
     * @return list<array<string, mixed>>
     */
    private function gettingStarted(User $user, Collection $screens, ?int $mediaCount): array
    {
        $steps = [];

        if ($user->can('screen-view') && $user->can('screen-store')) {
            $steps[] = [
                'key' => 'pair', 'done' => $screens->isNotEmpty(),
                'text' => 'Pair your first screen', 'detail' => 'Open the player on the TV and type the code it shows.',
                'href' => route('screens.view', ['pair' => 1]),
            ];
        }

        if ($user->can('media-view') && $user->can('media-store')) {
            $steps[] = [
                'key' => 'upload', 'done' => (bool) $mediaCount,
                'text' => 'Upload a picture or a video', 'detail' => 'Drop it on the Media page, several at once if you like.',
                'href' => route('media.view'),
            ];
        }

        if ($user->can('screen-playlist') && $screens->isNotEmpty()) {
            $first = $screens->first();
            $steps[] = [
                'key' => 'play', 'done' => $screens->contains(fn (Screen $screen) => $screen->playlist_items_count > 0),
                'text' => 'Put something on a screen', 'detail' => "Add files to {$first->name}'s playlist.",
                'href' => route('screens.show', $first),
            ];
        }

        return collect($steps)->every(fn (array $step) => $step['done']) ? [] : $steps;
    }

    /**
     * The one line that counts what a list of LIST_LENGTH left out ("3 more screens are offline"), or none. It carries
     * its `count`, so the number beside "Needs attention" is every one of them, not the lines.
     *
     * @return list<array<string, mixed>>
     */
    private function andMore(string $key, int $total, string $tone, string $text, string $detail, string $href): array
    {
        $more = $total - self::LIST_LENGTH;

        return $more > 0
            ? [['key' => $key, 'tone' => $tone, 'text' => "{$more} {$text}", 'detail' => $detail, 'href' => $href, 'count' => $more]]
            : [];
    }

    /**
     * @param  Builder<ActivityLog>  $query
     * @return list<array<string, mixed>>
     */
    private function recentActivity(Builder $query): array
    {
        return $query->latest('id')->limit(self::LIST_LENGTH)->get(['id', 'actor_name', 'description', 'created_at'])
            ->map(fn (ActivityLog $entry) => [
                'id' => $entry->id,
                'who' => $entry->actor_name,
                'what' => $entry->description,
                'when' => $entry->created_at?->diffForHumans(),
                'at' => $entry->created_at?->toIso8601String(),
            ])->all();
    }
}
