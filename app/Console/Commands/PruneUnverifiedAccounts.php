<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * An account never confirmed is removed after User::UNVERIFIED_DAYS days, with the empty organization it made at signup
 * (owner's rule, 2026-09-29: "delete after 7 days") — scheduled daily in routes/console.php. An unconfirmed account
 * can do nothing that uses the server's space, so its organization is empty — unless somebody above the organizations put
 * somebody or something in it (a member, a file in its library): then the account is left alone, for a person to
 * decide, and the run says so.
 */
class PruneUnverifiedAccounts extends Command
{
    protected $signature = 'accounts:prune-unverified';

    protected $description = 'Remove accounts never confirmed within '.User::UNVERIFIED_DAYS.' days, with the empty organizations they made';

    /** What an organization may hold, table by table (each with an organization_id): an organization holding any of it is not empty. */
    private const WHAT_AN_ORGANIZATION_HOLDS = ['media', 'screens', 'channels', 'builder_ads', 'builder_assets', 'invitations', 'roles'];

    public function handle(): int
    {
        $removed = 0;
        $kept = 0;

        // By id, never by page: rows go as it walks, and a page counted by offset would skip the ones after them.
        User::whereNull('email_verified_at')
            ->where('created_at', '<=', now()->subDays(User::UNVERIFIED_DAYS))
            ->lazyById()
            ->each(function (User $candidate) use (&$removed, &$kept) {
                DB::transaction(function () use ($candidate, &$removed, &$kept) {
                    // The organization rows first, as every change to a team or a library takes them (OrganizationTeam::changeTeam,
                    // OrganizationStorage::withRoom), then the person's own row: nobody is put in, nothing is uploaded and
                    // the link is not opened between the look and the delete.
                    $organizationIds = DB::table('organization_user')->where('user_id', $candidate->id)->pluck('organization_id');
                    $organizations = Organization::whereIn('id', $organizationIds)->orderBy('id')->lockForUpdate()->get();
                    $user = User::whereKey($candidate->id)->lockForUpdate()->first();

                    if ($user === null || $user->hasVerifiedEmail()) {
                        return;   // gone meanwhile, or confirmed a moment ago
                    }

                    $why = $this->whyKept($user->id, $organizationIds);

                    if ($why !== null) {
                        $kept++;
                        $this->warn("Kept {$user->email}: {$why}.");

                        return;
                    }

                    $email = $user->email;

                    // The organization first — its purge (Organization::booted) runs while the person is still its member.
                    $organizations->each(fn (Organization $organization) => $organization->delete());
                    $user->delete();

                    ActivityLog::record('account.pruned', null,
                        "Removed {$email}, never confirmed within ".User::UNVERIFIED_DAYS.' days'
                        .($organizations->isEmpty() ? '' : ', with the organization '.$organizations->pluck('name')->implode(', ')),
                        organizationId: $organizations->first()?->id);

                    $removed++;
                });
            });

        $this->info("{$removed} account(s) removed.".($kept > 0 ? " {$kept} kept: an organization of theirs has people or things in it." : ''));

        return self::SUCCESS;
    }

    /** Why this account stays, or null when every organization of theirs is theirs alone and empty. */
    private function whyKept(int $userId, Collection $organizationIds): ?string
    {
        if ($organizationIds->contains(0)) {
            return 'they are on the platform team';
        }

        if (DB::table('organization_user')->whereIn('organization_id', $organizationIds)->where('user_id', '!=', $userId)->exists()) {
            return 'an organization of theirs has other people in it';
        }

        $holding = collect(self::WHAT_AN_ORGANIZATION_HOLDS)
            ->first(fn (string $table) => DB::table($table)->whereIn('organization_id', $organizationIds)->exists());

        return $holding !== null ? "an organization of theirs is not empty ({$holding})" : null;
    }
}
