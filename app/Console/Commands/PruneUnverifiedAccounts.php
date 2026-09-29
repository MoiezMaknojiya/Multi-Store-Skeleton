<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Store;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * An account never confirmed is removed after User::UNVERIFIED_DAYS days, with the empty store it made at signup
 * (owner's rule, 2026-09-29: "delete after 7 days") — scheduled daily in routes/console.php. An unconfirmed account
 * can do nothing that uses the server's space, so its store is empty — unless somebody above the stores put
 * somebody or something in it (a member, a file in its library): then the account is left alone, for a person to
 * decide, and the run says so.
 */
class PruneUnverifiedAccounts extends Command
{
    protected $signature = 'accounts:prune-unverified';

    protected $description = 'Remove accounts never confirmed within '.User::UNVERIFIED_DAYS.' days, with the empty stores they made';

    /** What a store may hold, table by table (each with a store_id): a store holding any of it is not empty. */
    private const WHAT_A_STORE_HOLDS = ['media', 'screens', 'dayparts', 'channels', 'builder_ads', 'builder_assets', 'invitations', 'roles'];

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
                    // The store rows first, as every change to a team or a library takes them (StoreTeam::changeTeam,
                    // StoreStorage::withRoom), then the person's own row: nobody is put in, nothing is uploaded and
                    // the link is not opened between the look and the delete.
                    $storeIds = DB::table('store_user')->where('user_id', $candidate->id)->pluck('store_id');
                    $stores = Store::whereIn('id', $storeIds)->orderBy('id')->lockForUpdate()->get();
                    $user = User::whereKey($candidate->id)->lockForUpdate()->first();

                    if ($user === null || $user->hasVerifiedEmail()) {
                        return;   // gone meanwhile, or confirmed a moment ago
                    }

                    $why = $this->whyKept($user->id, $storeIds);

                    if ($why !== null) {
                        $kept++;
                        $this->warn("Kept {$user->email}: {$why}.");

                        return;
                    }

                    $email = $user->email;

                    // The store first — its purge (Store::booted) runs while the person is still its member.
                    $stores->each(fn (Store $store) => $store->delete());
                    $user->delete();

                    ActivityLog::record('account.pruned', null,
                        "Removed {$email}, never confirmed within ".User::UNVERIFIED_DAYS.' days'
                        .($stores->isEmpty() ? '' : ', with the store '.$stores->pluck('name')->implode(', ')),
                        storeId: $stores->first()?->id);

                    $removed++;
                });
            });

        $this->info("{$removed} account(s) removed.".($kept > 0 ? " {$kept} kept: a store of theirs has people or things in it." : ''));

        return self::SUCCESS;
    }

    /** Why this account stays, or null when every store of theirs is theirs alone and empty. */
    private function whyKept(int $userId, Collection $storeIds): ?string
    {
        if ($storeIds->contains(0)) {
            return 'they are on the platform team';
        }

        if (DB::table('store_user')->whereIn('store_id', $storeIds)->where('user_id', '!=', $userId)->exists()) {
            return 'a store of theirs has other people in it';
        }

        $holding = collect(self::WHAT_A_STORE_HOLDS)
            ->first(fn (string $table) => DB::table($table)->whereIn('store_id', $storeIds)->exists());

        return $holding !== null ? "a store of theirs is not empty ({$holding})" : null;
    }
}
