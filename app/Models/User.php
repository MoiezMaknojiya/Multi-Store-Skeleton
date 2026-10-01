<?php

namespace App\Models;

use App\Notifications\ConfirmNewEmailNotification;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Auth\MustVerifyEmail as ConfirmsItsEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * An account: a login and nothing more (docs/ORGANIZATION-SPEC.md rule 1). Nobody owns it,
 * and it gives no power by itself — power comes from memberships (`organization_user`): a role in an
 * organization, or a platform role on the organization_id = 0 row.
 */
class User extends Authenticatable implements MustVerifyEmail
{
    use ConfirmsItsEmail, HasFactory, Notifiable;

    /**
     * An account never confirmed is removed this many days after it was made, with the organization it made alone
     * (owner's rule, 2026-09-29 — PruneUnverifiedAccounts). Until it is confirmed it can do nothing that uses
     * the server's space: the `verified` middleware sends it to "Check your inbox".
     */
    public const UNVERIFIED_DAYS = 7;

    /**
     * Emails that confirm an address, as [how many, in how many seconds] (owner's rule, 2026-09-29): the signup's,
     * "send it again" on either page and a new address typed on the profile all draw on them — counted inside
     * sendALink(), so no door can skip it. Each is an email to an address somebody typed, so neither one account
     * nor one visitor (by IP, across every account they make) can be used to fill other people's inboxes.
     */
    public const LINKS_PER_ACCOUNT = ['minute' => [3, 60], 'hour' => [20, 3600]];

    public const LINKS_PER_VISITOR = ['hour' => [30, 3600]];

    protected $fillable = [
        'first_name', 'last_name', 'phone', 'email', 'password',
    ];

    /**
     * A deleted account leaves nothing pointing at it (owner's rules, 2026-09-17): its memberships go through their
     * foreign key, what it made stays with its organizations (`created_by` empties the same way) — and here its sign-ins on
     * every device, its password-reset link and every invitation waiting for its email go too, and the activity
     * log forgets whose id it was: on MySQL that table is partitioned and cannot carry a foreign key, so nothing
     * else would empty `actor_id` (the entry keeps the person's name in `actor_name`).
     */
    protected static function booted(): void
    {
        static::deleted(function (User $user) {
            $user->endEverySession();

            DB::table(config('auth.passwords.users.table', 'password_reset_tokens'))->where('email', $user->email)->delete();
            $user->invitationsToEmail()->delete();
            DB::table('activity_logs')->where('actor_id', $user->id)->update(['actor_id' => null]);
        });
    }

    /** Every sign-in of this account ends, on every device (the database session driver keeps them in a table). */
    public function endEverySession(): void
    {
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $this->id)->delete();
        }
    }

    /** The invitations addressed to this account's email — to any organization or to the platform team, expired ones included. */
    public function invitationsToEmail(): Builder
    {
        return Invitation::where('email', Invitation::normalizeEmail($this->email));
    }

    public function getNameAttribute(): string
    {
        return trim($this->{'first_name'}.' '.$this->{'last_name'});
    }

    /** "Forgot password" goes out in the app's own template, not Laravel's stock one. */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    /** The signup's "confirm your email" goes out in the app's own template too. */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification);
    }

    /** The link that confirms this account's email: null once it is on its way, or the words that say why not. */
    public function sendVerificationLink(): ?string
    {
        return $this->sendALink(fn () => $this->sendEmailVerificationNotification());
    }

    /**
     * The link that confirms a changed address, to that address — the account keeps its own until it is opened:
     * null once it is on its way, or the words that say why not.
     */
    public function sendNewEmailLink(): ?string
    {
        if ($this->pending_email === null) {
            return 'There is no change of email waiting to be confirmed.';
        }

        return $this->sendALink(fn () => Notification::route('mail', $this->pending_email)->notify(new ConfirmNewEmailNotification($this)));
    }

    /**
     * Null while another email that confirms an address may go out for this account now, or the words that say when
     * one may. Counts nothing: a form that sends one asks first, before it saves anything (the Profile's email).
     */
    public function linkRefusal(): ?string
    {
        foreach ($this->linkBudgets() as [$key, $most]) {
            if (RateLimiter::tooManyAttempts($key, $most)) {
                $seconds = RateLimiter::availableIn($key);

                return 'Too many emails asked for. Try again in '
                    .($seconds < 2 ? 'a second' : ($seconds < 90 ? "{$seconds} seconds" : (int) ceil($seconds / 60).' minutes')).'.';
            }
        }

        return null;
    }

    /**
     * Within the budgets, $send — counted, whether the mail server takes it or not — and a mail server that refuses
     * reported, never thrown (as Invitation::sendLink does).
     */
    private function sendALink(callable $send): ?string
    {
        if (($refusal = $this->linkRefusal()) !== null) {
            return $refusal;
        }

        foreach ($this->linkBudgets() as [$key, , $seconds]) {
            RateLimiter::hit($key, $seconds);
        }

        try {
            $send();

            return null;
        } catch (Throwable $e) {
            report($e);

            return 'The email could not be sent just now. Send the link again in a minute.';
        }
    }

    /**
     * Every budget this account's next link draws on, as [key, how many, in how many seconds]: its own, and the
     * visitor's — read from the request, since every door that sends one is a page somebody submitted.
     *
     * @return list<array{0: string, 1: int, 2: int}>
     */
    private function linkBudgets(): array
    {
        $budgets = [];

        foreach (self::LINKS_PER_ACCOUNT as $window => [$most, $seconds]) {
            $budgets[] = ["confirm-links:account:{$window}:{$this->id}", $most, $seconds];
        }

        foreach (self::LINKS_PER_VISITOR as $window => [$most, $seconds]) {
            $budgets[] = ["confirm-links:visitor:{$window}:".request()->ip(), $most, $seconds];
        }

        return $budgets;
    }

    /* ── Per-request memo caches ──────────────────────────────────────────
     * A model instance lives for one request, and these lookups get hit by
     * every @can check, scope, and blade config — without memoization a single
     * page render repeats the same queries dozens of times. */
    private ?bool $isSuperAdminMemo = null;

    private bool $globalRoleResolved = false;

    private ?Role $globalRoleMemo = null;

    /** @var array<string, array<int, string>> permission names keyed by organization context */
    private array $permissionNamesMemo = [];

    /** refresh() also flushes the permission memos, so a refreshed instance
     *  re-reads its roles/permissions from the database. */
    public function refresh(): static
    {
        $this->isSuperAdminMemo = null;
        $this->globalRoleResolved = false;
        $this->globalRoleMemo = null;
        $this->permissionNamesMemo = [];

        return parent::refresh();
    }

    /** Super-Admin is a platform role, so it is held on the organization_id = 0 row (the tiers are exclusive). */
    public function isSuperAdmin(): bool
    {
        return $this->isSuperAdminMemo ??= ($this->globalRole()?->isSuperAdmin() ?? false);
    }

    /**
     * The primary super admin: the first person ever given the Super-Admin role. Nobody else
     * may delete them or take their role, and only they may do either to another super admin
     * (docs/ORGANIZATION-SPEC.md rule 24).
     */
    public static function primarySuperAdminId(): ?int
    {
        $roleId = Role::superAdminId();

        $userId = $roleId === null ? null : DB::table('organization_user')
            ->where('role_id', $roleId)
            ->orderBy('id')
            ->value('user_id');

        return $userId === null ? null : (int) $userId;
    }

    public function isPrimarySuperAdmin(): bool
    {
        return $this->id === self::primarySuperAdminId();
    }

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** The organizations this person is a member of (the platform row, organization_id = 0, has no organization and is not among them). */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_user')
            ->withPivot('role_id')
            ->withTimestamps();
    }

    /**
     * The role this person holds in the currently selected organization.
     */
    public function currentRole(): ?Role
    {
        $organizationId = session('current_organization_id');
        if (! $organizationId) {
            return null;
        }

        $pivot = $this->organizations()->wherePivot('organization_id', $organizationId)->first();
        if (! $pivot || ! $pivot->pivot->role_id) {
            return null;
        }

        return Role::find($pivot->pivot->role_id);
    }

    /**
     * The organization this person works in, when the platform has paused it (its Active switch off; owner, 2026-09-30):
     * their membership stands, but its pages are closed to them (EnsureOrganizationIsActive), their role there grants nothing
     * (contextPermissionNames) and the dashboard says why, until the platform turns it back on. Its screens keep
     * playing. Null above the organizations, with no organization chosen, in an organization they are not a member of, or in an active one.
     */
    public function pausedOrganization(): ?Organization
    {
        $organizationId = (int) session('current_organization_id');

        if ($organizationId === 0 || $this->globalRole() !== null) {
            return null;
        }

        return $this->organizations()->where('organizations.id', $organizationId)->where('organizations.is_active', false)->first();
    }

    /**
     * Whether Settings has an Organizations tab for this person (owner's rule, 2026-09-17): an organization member working in
     * an organization whose role there holds View Organizations. Without it "Your organizations" stays on the profile, so anybody
     * can still leave an organization.
     */
    public function hasOrganizationsTab(): bool
    {
        return $this->globalRole() === null && (bool) session('current_organization_id') && $this->can('organization-view');
    }

    /**
     * The platform role, held on the organization_id = 0 row, if any: Super-Admin, or any role the
     * super admin made for the platform team.
     */
    public function globalRole(): ?Role
    {
        if (! $this->globalRoleResolved) {
            $roleId = DB::table('organization_user')
                ->where('user_id', $this->id)
                ->where('organization_id', 0)
                ->value('role_id');

            $this->globalRoleMemo = $roleId ? Role::find($roleId) : null;
            $this->globalRoleResolved = true;
        }

        return $this->globalRoleMemo;
    }

    /**
     * Whether this person holds a permission where they stand: their platform role when they have one
     * (the tiers are exclusive, so it always wins — an organization left in the session changes nothing),
     * otherwise their role in the selected organization.
     */
    public function hasPermissionInCurrentOrganization(string $permissionName): bool
    {
        return in_array($permissionName, $this->contextPermissionNames(), true);
    }

    /**
     * Every permission name the user holds in the current context (their platform
     * role, or else the session's organization role). Loaded ONCE per request per context —
     * a page render fires a dozen @can checks and they all share this list.
     *
     * @return array<int, string>
     */
    public function contextPermissionNames(): array
    {
        // The platform team never works inside an organization (the tiers are exclusive), so an organization left
        // in a platform account's session can never swap its platform role for no role at all.
        $platformRole = $this->globalRole();
        $key = $platformRole !== null ? 'platform' : 'organization:'.(int) session('current_organization_id');

        if (! array_key_exists($key, $this->permissionNamesMemo)) {
            // A paused organization grants its people nothing until the platform turns it back on (pausedOrganization).
            $role = $platformRole ?? (session('current_organization_id') && $this->pausedOrganization() === null ? $this->currentRole() : null);

            $this->permissionNamesMemo[$key] = $role
                ? $role->permissions()->pluck('name')->all()
                : [];
        }

        return $this->permissionNamesMemo[$key];
    }
}
