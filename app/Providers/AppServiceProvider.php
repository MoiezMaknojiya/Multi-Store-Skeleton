<?php

namespace App\Providers;

use App\Http\Controllers\Platform\ImpersonateController;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->registerRateLimiters();

        // Register gates for every permission in the database
        try {
            Permission::all()->each(function (Permission $permission) {
                Gate::define($permission->name, function (User $user) use ($permission) {
                    return $user->hasPermissionInCurrentStore($permission->name);
                });
            });
        } catch (\Exception) {
            // Table might not exist during migration
        }

        $this->registerNetworkAdGates();
        $this->registerTierGates();

        // A super admin holds every permission, whatever the role rows say (owner's rule, 2026-09-16:
        // "sab matlab sab") — a permission made later on the Permissions page, or one renamed, included.
        // It answers permission checks only: rules that are not permissions still stand (the primary
        // super admin's protection, Super-Admin and the Owner role never deleted, a store's last
        // Owner). `network-ads-toggle` is a place, not a permission — "inside a shop, while logged in
        // as one of its people" — so it keeps its own test.
        Gate::before(function (User $user, string $ability) {
            if ($ability === 'network-ads-toggle') {
                return null;
            }

            return $user->isSuperAdmin() ? true : null;
        });

        // Every sign-in starts with no impersonation in the session. Without this, a session
        // whose impersonated account was deleted mid-way kept the super admin's id through the
        // next person's login (regenerate() keeps session data), and "stop" handed it to them.
        // ImpersonateController::start writes its keys after its own login, so it is unaffected.
        Event::listen(Login::class, fn () => session()->forget(ImpersonateController::SESSION_KEYS));

        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            URL::forceScheme('https');
        }
    }

    /**
     * The two abilities behind network advertising.
     *
     * Written by hand rather than seeded as permission rows, and deliberately so. A
     * permission row can be handed to any role, including a store's own — and a
     * campaign has no store, so a store user holding `campaign-view` would see every
     * brand's contract across the whole network. That is the one wall this app never
     * breaks, so these two are simply not grantable.
     */
    private function registerNetworkAdGates(): void
    {
        // Campaigns belong to the platform, not to any shop.
        Gate::define('campaign-manage', fn (User $user) => $user->isSuperAdmin());

        /**
         * Whether a shop and its screens carry advertising is the PLATFORM owner's
         * setting, agreed in the deal — not something a shopkeeper flips on a Tuesday.
         * A super admin cannot enter a store directly (only "Log in as" does that), so
         * the control appears exactly where they can reach it: inside an impersonated
         * session, to nobody else.
         *
         * The original id is re-checked against a live super admin every time, the
         * same way ImpersonateController::stop does — a session left over from a user
         * who has since lost that rank must not still open this door.
         */
        Gate::define('network-ads-toggle', function (User $user) {
            $originalId = session('impersonating_original_id');

            return $originalId !== null
                && (int) session('impersonating_user_id') === $user->id
                && User::find($originalId)?->isSuperAdmin() === true;
        });
    }

    /**
     * The tiers behind the platform's own pages — the second lock on their routes.
     *
     * `global-tier` is acting ABOVE the stores: a super admin, or a user holding a global
     * role. It guards what only works across every shop at once: the activity log's yearly
     * maintenance and storage panel (a year is dropped for every shop together), giving a
     * store an owner, every account, "Log in as" and the platform team. `super-admin-tier`
     * guards the permission catalogue, which the owner keeps with the Super-Admin role alone,
     * the platform team's own management (invitations, taking a platform role away) and
     * putting people in stores from the Users page.
     *
     * Channels and the activity log themselves are NOT tier-locked: a store's role may carry
     * those permissions (Permission::STORE_SCOPED), and their controllers then answer for that
     * one store only. The Stores page and the accounts pages are: a store's own people change
     * their store in Settings → Stores and see each other on the Members page (owner's rules,
     * 2026-09-17). What a store's role may never carry, RoleController refuses; these gates stand
     * on the routes themselves, so a row that somehow reached the wrong role still opens
     * nothing. They also close the door during
     * "Log in as", where the person acting is a store member.
     */
    private function registerTierGates(): void
    {
        Gate::define('global-tier', fn (User $user) => $user->globalRole() !== null);

        Gate::define('super-admin-tier', fn (User $user) => $user->isSuperAdmin());
    }

    /**
     * Named rate limiters — one bucket per purpose, never one shared bucket.
     *
     * The bare `throttle:30,1` form looks like it gives a route its own budget.
     * It does not: for a request with no logged-in user Laravel builds the key
     * from the DOMAIN AND IP ONLY — the route is not part of it — so every such
     * route on the site counts into the SAME counter for that visitor.
     *
     * That is not academic here. A shop's TVs and the owner's laptop sit behind
     * one router, so they share one public IP. Each paired screen sends three
     * requests a minute (two playlist polls plus a heartbeat), and all of them
     * used to land in the same counter as the signup form: about four screens
     * was enough to make POST /register answer 429, and about ten was enough to
     * stop a new TV getting a pairing code — while the TVs already running
     * carried on happily, so nothing looked broken.
     *
     * Every limiter below therefore states its key explicitly, prefixed with its
     * own name so two limiters can never collide, and the screen endpoints count
     * PER DEVICE rather than per IP: one TV misbehaving must not take the shop's
     * other screens down with it.
     */
    private function registerRateLimiters(): void
    {
        // A screen asking for a pairing code has nothing to identify it yet, so
        // this is the one device limiter that has to key on the IP. A screen only
        // registers once per code (the code is cached for its 15-minute life), so
        // 30 a minute is far above any honest shop and still caps table-filling.
        RateLimiter::for('device-register', fn (Request $request) => Limit::perMinute(30)
            ->by('device-register:'.$request->ip()));

        // Waiting to be claimed, a screen polls every 30 seconds — 2 a minute,
        // plus one extra the moment a player page is reopened. 60 leaves room for
        // a TV that is restarted repeatedly during setup without punishing the
        // shop next door on the same IP; the second limit is the ceiling for the
        // whole building.
        RateLimiter::for('device-pair', function (Request $request) {
            // A limiter runs BEFORE the endpoint's validation, so it has to survive any shape a
            // visitor sends: ?device_uuid[]=x arrives as an array, and an array in a cache key is an
            // "Array to string conversion" — a 500 on an endpoint that is open on purpose. Anything
            // that is not one plain value falls back to the address, like a request with no uuid.
            $uuid = $request->query('device_uuid');
            $uuid = is_scalar($uuid) ? trim((string) $uuid) : '';

            return [
                Limit::perMinute(60)->by('device-pair:uuid:'.($uuid !== '' ? $uuid : $request->ip())),
                Limit::perMinute(600)->by('device-pair:ip:'.$request->ip()),
            ];
        });

        // A paired screen sends three requests a minute. 60 is twenty times that,
        // which absorbs a reload loop or a flapping connection without ever
        // reaching a screen that is behaving. The token is hashed because a cache
        // key is not a place to keep a secret; a missing token hashes to a single
        // shared bucket, which is what we want for junk traffic. Note this limiter
        // runs BEFORE device.token so a wrong token is throttled too.
        RateLimiter::for('device-api', function (Request $request) {
            $token = $request->bearerToken() ?? '';

            return [
                Limit::perMinute(60)->by('device-api:device:'.hash('sha256', $token)),
                Limit::perMinute(1200)->by('device-api:ip:'.$request->ip()),
            ];
        });

        // Public signup. Same 10 a minute as before — the point of naming it is
        // that the TVs can no longer spend this budget.
        RateLimiter::for('signup', fn (Request $request) => Limit::perMinute(10)
            ->by('signup:'.$request->ip()));

        // The whole authenticated admin surface, per person. It is one shared
        // budget on purpose — the cap is on a logged-in human, not on any single
        // page. Named rather than left as `throttle:240,1` so that a second bare
        // limiter added to an authed route later cannot silently share this
        // counter, the way the guest routes used to share theirs.
        RateLimiter::for('admin', fn (Request $request) => Limit::perMinute(240)
            ->by('admin:'.($request->user()?->id ?: $request->ip())));

        // Every invitation sends an email to an address somebody typed. Capped per person
        // per hour, so a compromised account cannot turn the panel into a mail cannon.
        RateLimiter::for('invitations', fn (Request $request) => Limit::perHour(60)
            ->by('invitations:'.($request->user()?->id ?: $request->ip())));

        // The public end of the link — opened, accepted, registered or declined — keyed on
        // the visitor, because a guest has nothing else to be known by.
        RateLimiter::for('invitation-response', fn (Request $request) => Limit::perMinute(20)
            ->by('invitation-response:'.$request->ip()));

        // Installing a font reaches OUT of this server to Google and writes files, so it is counted
        // even though it needs `ad-store` or `ad-update` to get here at all: a loop in a client must
        // not be able to pull a hundred families down. One family is one request, and a design needs
        // a handful.
        RateLimiter::for('font-install', fn (Request $request) => Limit::perMinute(10)
            ->by('font-install:'.($request->user()?->id ?: $request->ip())));

        // The forgotten-password pair. The password broker has a throttle of its own, but it only
        // stops the SAME address being mailed twice within a minute — it does nothing about a list
        // of addresses being walked one at a time, which sends one email per address (the form's
        // answer is the same for every address, so it reads nothing back — the mail is the harm).
        // Keyed on the visitor like the invitation link's end; a reset is rare enough that 10 a
        // minute is far above anybody who has simply mistyped their new password.
        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute(10)
            ->by('password-reset:'.$request->ip()));
    }
}
