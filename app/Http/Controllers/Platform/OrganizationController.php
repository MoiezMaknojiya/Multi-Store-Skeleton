<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Concerns\ConfirmsPassword;
use App\Http\Controllers\Concerns\HandlesCrudData;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\OrganizationTeam;
use Illuminate\Database\Eloquent\Builder;
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
 * Every organization, from the platform's side (docs/ORGANIZATION-SPEC.md rule 18): made for a customer with an
 * Owner invitation, edited, deleted for good, and given an Owner while it has none (owner's rule, 2026-09-17). An
 * organization's own people change the organization they work in from Settings → Organizations instead (OrganizationSettingsController), so
 * the page and its writes sit behind the global tier.
 *
 * switch() is how a member moves between the organizations they belong to.
 */
class OrganizationController extends Controller
{
    use ConfirmsPassword, HandlesCrudData;

    public function __construct(private OrganizationTeam $team) {}

    public function index(): View
    {
        return view('organizations.index', ['states' => Organization::US_STATES]);
    }

    /** Every organization, with its member count and what the viewer may do to each. */
    public function data(Request $request): JsonResponse
    {
        $actor = $request->user();
        $ownerRoleId = Role::owner()->id;
        $query = Organization::query()
            ->withCount([
                'users as members_count',
                'users as owners_count' => fn (Builder $users) => $users->where('organization_user.role_id', $ownerRoleId),
            ])
            ->orderBy('name');

        // Only the platform owner sees the advertising column, so only they pay for the two
        // counts behind it: "on" with no screens switched, or only some, must not look alike.
        if (Gate::allows('campaign-manage')) {
            $query->withCount([
                'screens',
                'screens as ad_screens_count' => fn (Builder $q) => $q->where('accepts_network_ads', true),
            ]);
        }

        return $this->paginatedResponse(
            $request, $query, ['name', 'city'], 'organizations', ['*'],
            fn (Collection $organizations) => $this->attachAbilities($organizations, $actor)
        );
    }

    /** A new organization for a customer, with an Owner invitation to the email given. */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([...$this->detailRules(), 'owner_email' => ['required', 'string', 'email', 'max:255']], [
            'zip_code.regex' => 'Zip code can only contain numbers.',
        ]);

        $email = Invitation::normalizeEmail($validated['owner_email']);
        $this->ensureEmailCanOwnAnOrganization($email, 'owner_email');

        [$organization, $invitation, $token] = DB::transaction(function () use ($validated, $email) {
            $organization = Organization::create([
                ...collect($validated)->except('owner_email')->all(),
                'created_by' => auth()->id(),
            ]);

            return [$organization, ...Invitation::open($organization, $email, Role::owner(), auth()->user())];
        });

        $sent = $invitation->sendLink($token);

        ActivityLog::record('organization.created', $organization, "Created organization {$organization->name} and invited {$email} to own it");

        return response()->json([
            'message' => $sent
                ? "Organization created. An invitation to own it was sent to {$email}."
                : "Organization created, but the invitation email to {$email} could not be sent. Use Invite owner to send it again.",
            'email_sent' => $sent,
            'organization' => $organization,
        ], 201);
    }

    public function update(Request $request, Organization $organization): JsonResponse
    {
        $validated = $request->validate($this->detailRules(), [
            'zip_code.regex' => 'Zip code can only contain numbers.',
        ]);

        $organization->update($validated);

        // Pausing an organization closes it to its own people (EnsureOrganizationIsActive), so it is said as what it is.
        [$action, $description, $message] = match (true) {
            $organization->wasChanged('is_active') && ! $organization->is_active => ['organization.paused', "Paused organization {$organization->name}", "{$organization->name} is paused."],
            $organization->wasChanged('is_active') => ['organization.resumed', "Turned organization {$organization->name} back on", "{$organization->name} is active again."],
            default => ['organization.updated', "Updated organization {$organization->name}", "Organization {$organization->name} updated."],
        };

        ActivityLog::record($action, $organization, $description);

        return response()->json(['message' => $message, 'organization' => $organization]);
    }

    /** Delete an organization and everything it owns (Organization::purgeContents) — the name typed, and the password. */
    public function destroy(Request $request, Organization $organization): JsonResponse
    {
        $request->validate([
            'confirm_name' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail) use ($organization) {
                if ($value !== $organization->name) {
                    $fail('Type the organization name exactly as it is shown.');
                }
            }],
        ], [
            'confirm_name.required' => 'Type the organization name to confirm.',
        ]);

        $this->confirmPassword($request);

        $name = $organization->name;
        $screens = $organization->screens()->count();
        $media = $organization->media()->count();

        DB::transaction(fn () => $organization->delete());

        ActivityLog::record('organization.deleted', null,
            "Deleted organization {$name} with its {$screens} ".str('screen')->plural($screens)." and {$media} media ".str('file')->plural($media),
            organizationId: $organization->id);

        return response()->json(['message' => "{$name} was deleted."]);
    }

    /**
     * Give an organization without an Owner one, from the platform's side — an organization left without one, or made for a customer
     * who never accepted (owner's rule, 2026-09-17: an organization that has an Owner is not offered this; more Owners come from
     * its own Members page, or Users → Organizations). A member of the organization becomes Owner at once; anybody else is invited.
     */
    public function inviteOwner(Request $request, Organization $organization): JsonResponse
    {
        $validated = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);
        $email = Invitation::normalizeEmail($validated['email']);
        $ownerRole = Role::owner();

        if ($this->team->ownerCount($organization) > 0) {
            throw ValidationException::withMessages(['email' => "{$organization->name} already has an Owner."]);
        }

        $member = User::whereRaw('lower(email) = ?', [$email])->first();

        if ($member !== null && $this->team->isMember($member, $organization)) {
            $replaced = DB::transaction(function () use ($organization, $member, $email, $ownerRole) {
                DB::table('organization_user')->where('organization_id', $organization->id)->where('user_id', $member->id)
                    ->update(['role_id' => $ownerRole->id, 'updated_at' => now()]);

                return $this->retireOwnerInvitations($organization, $email);
            });

            ActivityLog::record('organization.owner_assigned', $organization, "Made {$member->name} ({$email}) an Owner of {$organization->name}".$this->replacedNote($replaced));

            return response()->json(['message' => "{$member->name} is now an Owner of {$organization->name}.".$this->noLongerWorks($replaced)]);
        }

        $this->ensureEmailCanOwnAnOrganization($email, 'email');

        [$invitation, $token, $renewed, $replaced] = DB::transaction(function () use ($organization, $email, $ownerRole) {
            $replaced = $this->retireOwnerInvitations($organization, $email);

            // The same address again — still open, expired, or invited by the organization to another
            // role — gets a fresh Owner link rather than a refusal.
            $existing = Invitation::forOrganization($organization)->where('email', $email)->first();

            if ($existing !== null) {
                $existing->update(['role_id' => $ownerRole->id, 'invited_by' => auth()->id()]);

                return [$existing, $existing->renew(), true, $replaced];
            }

            return [...Invitation::open($organization, $email, $ownerRole, auth()->user()), false, $replaced];
        });

        $sent = $invitation->fresh()->sendLink($token);

        ActivityLog::record('organization.owner_invited', $organization, ($renewed ? "Sent the invitation to own {$organization->name} to {$email} again" : "Invited {$email} to own {$organization->name}")
            .$this->replacedNote($replaced));

        $message = ($sent
            ? "An invitation to own {$organization->name} was sent to {$email}."
            : "The invitation to own {$organization->name} is ready, but the email to {$email} could not be sent. Try again in a moment.")
            .$this->noLongerWorks($replaced);

        return response()->json(['message' => $message, 'email_sent' => $sent], $renewed ? 200 : 201);
    }

    /** Switch the session to another organization the member belongs to. */
    public function switch(Request $request): RedirectResponse
    {
        $request->validate(['organization_id' => ['required', 'integer']]);
        $user = auth()->user();

        if ($user->globalRole() !== null) {
            abort(403, 'The platform team works above the organizations. Use "Log In As" to see an organization as one of its members.');
        }

        $organization = Organization::find($request->integer('organization_id'));
        abort_unless($organization !== null && $this->team->isMember($user, $organization), 403, 'You are not a member of this organization.');

        session(['current_organization_id' => $organization->id]);

        ActivityLog::record('organization.switched', $organization, "Switched into organization {$organization->name}");

        // Back to the page that sent the person to choose (bootstrap/app.php), or the dashboard.
        return redirect()->intended(route('dashboard'))->with('status', "Switched to {$organization->name}.");
    }

    /** @return array<string, array<int, mixed>> */
    private function detailRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'street' => ['required', 'string', 'max:255'],
            'suite' => ['nullable', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:100'],
            'state' => ['required', 'string', 'size:2', Rule::in(array_keys(Organization::US_STATES))],
            'zip_code' => ['required', 'string', 'max:10', 'regex:/^[0-9]+$/'],
            'country' => ['required', 'string', 'max:100'],
            // Switching an organization on or off is the platform's, never the organization's own.
            'is_active' => ['boolean'],
        ];
    }

    /** A platform account never owns an organization (rule 3). */
    private function ensureEmailCanOwnAnOrganization(string $email, string $field): void
    {
        if (User::whereRaw('lower(email) = ?', [$email])->first()?->globalRole() !== null) {
            throw ValidationException::withMessages([$field => 'This email belongs to a platform account and cannot own an organization.']);
        }
    }

    /** What the viewer may do to each row, so the page never offers what the controller would refuse. */
    private function attachAbilities(Collection $organizations, User $actor): void
    {
        $mayUpdate = $actor->can('organization-update');
        $mayDestroy = $actor->can('organization-destroy');
        $mayCreate = $actor->can('organization-store');
        $mayReadBilling = $actor->can('billing-view');

        $organizations->each(fn (Organization $organization) => $organization->can = [
            'update' => $mayUpdate,
            'destroy' => $mayDestroy,
            // Billing beside Edit (docs/BILLING-SPEC.md §4); its switches ask Change Billing, which the dialog is told when it opens.
            'billing' => $mayReadBilling,
            // Offered only while the organization has no Owner at all.
            'invite_owner' => $mayCreate && (int) $organization->owners_count === 0,
        ]);
    }

    /**
     * An organization with nobody in charge has one way in as its Owner at a time: the owner invitations to any other address
     * go, so a mistyped email stops working the moment the right person is invited or made Owner.
     *
     * @return Collection<int, Invitation>
     */
    private function retireOwnerInvitations(Organization $organization, string $email): Collection
    {
        $replaced = Invitation::forOrganization($organization)->where('role_id', Role::owner()->id)->where('email', '!=', $email)->get();
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
