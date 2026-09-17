<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Concerns\ConfirmsPassword;
use App\Http\Controllers\Concerns\HandlesCrudData;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Invitation;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\StoreTeam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Every store, from the platform's side (docs/STORE-ORGANIZATION-SPEC.md rule 18): made for a customer with an
 * Owner invitation, edited, deleted for good, and given an Owner while it has none (owner's rule, 2026-09-17). A
 * store's own people change the store they work in from Settings → Stores instead (StoreSettingsController), so
 * the page and its writes sit behind the global tier.
 *
 * switch() is how a member moves between the stores they belong to.
 */
class StoreController extends Controller
{
    use ConfirmsPassword, HandlesCrudData;

    public function __construct(private StoreTeam $team) {}

    public function index(): View
    {
        return view('stores.index', ['states' => Store::US_STATES]);
    }

    /** Every store, with its member count and what the viewer may do to each. */
    public function data(Request $request): JsonResponse
    {
        $actor = $request->user();
        $ownerRoleId = Role::owner()->id;
        $query = Store::query()
            ->withCount([
                'users as members_count',
                'users as owners_count' => fn ($users) => $users->where('store_user.role_id', $ownerRoleId),
            ])
            ->orderBy('name');

        // Only the platform owner sees the advertising column, so only they pay for the two
        // counts behind it: "on" with no screens switched, or only some, must not look alike.
        if (Gate::allows('campaign-manage')) {
            $query->withCount([
                'screens',
                'screens as ad_screens_count' => fn ($q) => $q->where('accepts_network_ads', true),
            ]);
        }

        return $this->paginatedResponse(
            $request, $query, ['name', 'city'], 'stores', ['*'],
            fn (Collection $stores) => $this->attachAbilities($stores, $actor)
        );
    }

    /** A new store for a customer, with an Owner invitation to the email given. */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([...$this->detailRules(), 'owner_email' => ['required', 'string', 'email', 'max:255']], [
            'zip_code.regex' => 'Zip code can only contain numbers.',
        ]);

        $email = Invitation::normalizeEmail($validated['owner_email']);
        $this->ensureEmailCanOwnAStore($email, 'owner_email');

        [$store, $invitation, $token] = DB::transaction(function () use ($validated, $email) {
            $store = Store::create([
                ...collect($validated)->except('owner_email')->all(),
                'created_by' => auth()->id(),
            ]);

            return [$store, ...Invitation::open($store, $email, Role::owner(), auth()->user())];
        });

        $sent = $invitation->sendLink($token);

        ActivityLog::record('store.created', $store, "Created store {$store->name} and invited {$email} to own it");

        return response()->json([
            'message' => $sent
                ? "Store created. An invitation to own it was sent to {$email}."
                : "Store created, but the invitation email to {$email} could not be sent. Use Invite owner to send it again.",
            'email_sent' => $sent,
            'store' => $store,
        ], 201);
    }

    public function update(Request $request, Store $store): JsonResponse
    {
        $validated = $request->validate($this->detailRules(), [
            'zip_code.regex' => 'Zip code can only contain numbers.',
        ]);

        $store->update($validated);

        ActivityLog::record('store.updated', $store, "Updated store {$store->name}");

        return response()->json(['message' => "Store {$store->name} updated.", 'store' => $store]);
    }

    /** Delete a store and everything it owns (Store::purgeContents) — the name typed, and the password. */
    public function destroy(Request $request, Store $store): JsonResponse
    {
        $request->validate([
            'confirm_name' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail) use ($store) {
                if ($value !== $store->name) {
                    $fail('Type the store name exactly as it is shown.');
                }
            }],
        ], [
            'confirm_name.required' => 'Type the store name to confirm.',
        ]);

        $this->confirmPassword($request);

        $name = $store->name;
        $screens = $store->screens()->count();
        $media = $store->media()->count();

        DB::transaction(fn () => $store->delete());

        ActivityLog::record('store.deleted', null,
            "Deleted store {$name} with its {$screens} ".str('screen')->plural($screens)." and {$media} media ".str('file')->plural($media),
            storeId: $store->id);

        return response()->json(['message' => "{$name} was deleted."]);
    }

    /**
     * Give a store without an Owner one, from the platform's side — a store left without one, or made for a customer
     * who never accepted (owner's rule, 2026-09-17: a store that has an Owner is not offered this; more Owners come from
     * its own Members page, or Users → Stores). A member of the store becomes Owner at once; anybody else is invited.
     */
    public function inviteOwner(Request $request, Store $store): JsonResponse
    {
        $validated = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);
        $email = Invitation::normalizeEmail($validated['email']);
        $ownerRole = Role::owner();

        if ($this->team->ownerCount($store) > 0) {
            throw ValidationException::withMessages(['email' => "{$store->name} already has an Owner."]);
        }

        $member = User::whereRaw('lower(email) = ?', [$email])->first();

        if ($member !== null && $this->team->isMember($member, $store)) {
            $replaced = DB::transaction(function () use ($store, $member, $email, $ownerRole) {
                DB::table('store_user')->where('store_id', $store->id)->where('user_id', $member->id)
                    ->update(['role_id' => $ownerRole->id, 'updated_at' => now()]);

                return $this->retireOwnerInvitations($store, $email);
            });

            ActivityLog::record('store.owner_assigned', $store, "Made {$member->name} ({$email}) an Owner of {$store->name}".$this->replacedNote($replaced));

            return response()->json(['message' => "{$member->name} is now an Owner of {$store->name}.".$this->noLongerWorks($replaced)]);
        }

        $this->ensureEmailCanOwnAStore($email, 'email');

        [$invitation, $token, $renewed, $replaced] = DB::transaction(function () use ($store, $email, $ownerRole) {
            $replaced = $this->retireOwnerInvitations($store, $email);

            // The same address again — still open, expired, or invited by the store to another
            // role — gets a fresh Owner link rather than a refusal.
            $existing = Invitation::forStore($store)->where('email', $email)->first();

            if ($existing !== null) {
                $existing->update(['role_id' => $ownerRole->id, 'invited_by' => auth()->id()]);

                return [$existing, $existing->renew(), true, $replaced];
            }

            return [...Invitation::open($store, $email, $ownerRole, auth()->user()), false, $replaced];
        });

        $sent = $invitation->fresh()->sendLink($token);

        ActivityLog::record('store.owner_invited', $store, ($renewed ? "Sent the invitation to own {$store->name} to {$email} again" : "Invited {$email} to own {$store->name}")
            .$this->replacedNote($replaced));

        $message = ($sent
            ? "An invitation to own {$store->name} was sent to {$email}."
            : "The invitation to own {$store->name} is ready, but the email to {$email} could not be sent. Try again in a moment.")
            .$this->noLongerWorks($replaced);

        return response()->json(['message' => $message, 'email_sent' => $sent], $renewed ? 200 : 201);
    }

    /** Switch the session to another store the member belongs to. */
    public function switch(Request $request): RedirectResponse
    {
        $request->validate(['store_id' => ['required', 'integer']]);
        $user = auth()->user();

        if ($user->globalRole() !== null) {
            abort(403, 'The platform team works above the stores. Use "Log in as" to see a store as one of its members.');
        }

        $store = Store::find($request->integer('store_id'));
        abort_unless($store !== null && $this->team->isMember($user, $store), 403, 'You are not a member of this store.');

        session(['current_store_id' => $store->id]);

        ActivityLog::record('store.switched', $store, "Switched into store {$store->name}");

        return redirect()->route('dashboard')->with('status', "Switched to {$store->name}.");
    }

    /** @return array<string, array<int, mixed>> */
    private function detailRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'street' => ['required', 'string', 'max:255'],
            'suite' => ['nullable', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:100'],
            'state' => ['required', 'string', 'size:2', Rule::in(array_keys(Store::US_STATES))],
            'zip_code' => ['required', 'string', 'max:10', 'regex:/^[0-9]+$/'],
            'country' => ['required', 'string', 'max:100'],
            // Switching a store on or off is the platform's, never the store's own.
            'is_active' => ['boolean'],
        ];
    }

    /** A platform account never owns a store (rule 3). */
    private function ensureEmailCanOwnAStore(string $email, string $field): void
    {
        if (User::whereRaw('lower(email) = ?', [$email])->first()?->globalRole() !== null) {
            throw ValidationException::withMessages([$field => 'This email belongs to a platform account and cannot own a store.']);
        }
    }

    /** What the viewer may do to each row, so the page never offers what the controller would refuse. */
    private function attachAbilities(Collection $stores, User $actor): void
    {
        $mayUpdate = $actor->can('store-update');
        $mayDestroy = $actor->can('store-destroy');
        $mayCreate = $actor->can('store-store');

        $stores->each(fn (Store $store) => $store->can = [
            'update' => $mayUpdate,
            'destroy' => $mayDestroy,
            // Offered only while the store has no Owner at all.
            'invite_owner' => $mayCreate && (int) $store->owners_count === 0,
        ]);
    }

    /**
     * A store with nobody in charge has one way in as its Owner at a time: the owner invitations to any other address
     * go, so a mistyped email stops working the moment the right person is invited or made Owner.
     *
     * @return Collection<int, Invitation>
     */
    private function retireOwnerInvitations(Store $store, string $email): Collection
    {
        $replaced = Invitation::forStore($store)->where('role_id', Role::owner()->id)->where('email', '!=', $email)->get();
        $replaced->each->delete();

        return $replaced;
    }

    /** @param  Collection<int, Invitation>  $replaced */
    private function replacedNote(Collection $replaced): string
    {
        return $replaced->isEmpty() ? '' : ', replacing the invitation for '.$replaced->pluck('email')->join(', ');
    }

    /** @param  Collection<int, Invitation>  $replaced */
    private function noLongerWorks(Collection $replaced): string
    {
        return $replaced->isEmpty() ? '' : ' The earlier invitation for '.$replaced->pluck('email')->join(', ').' no longer works.';
    }
}
