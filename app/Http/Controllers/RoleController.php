<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ConfirmsPassword;
use App\Models\ActivityLog;
use App\Models\Invitation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Services\StoreTeam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Roles, seen from where the person stands (docs/STORE-ORGANIZATION-SPEC.md §2–4 — owner's rules, 2026-09-17).
 *
 *  - On the platform (super admins): every role. The super admin makes a role and says what it is for —
 *    a store role, offered in every store, or a platform role for the team above the stores — and renames,
 *    changes and deletes any role but Super-Admin, including the custom roles stores made for themselves.
 *    The Owner role is a store role like the others, except that it is never deleted.
 *  - Inside a store: the store roles to read, and the store's own custom roles — made and managed by any
 *    member holding the role permissions, never beyond that member's own access.
 */
class RoleController extends Controller
{
    use ConfirmsPassword;

    /** What a role made on the platform is for: every store, or the platform team. */
    private const TYPES = ['store', 'platform'];

    private const PERMISSION_RULES = [
        'permissions' => ['required', 'array', 'min:1'],
        'permissions.*' => ['integer', 'distinct'],
    ];

    private const MESSAGES = [
        'permissions.required' => 'Choose at least one permission.',
        'permissions.min' => 'Choose at least one permission.',
        'type.required' => 'Choose what this role is for.',
        'type.in' => 'Choose what this role is for.',
    ];

    public function __construct(private StoreTeam $team) {}

    public function index(): View
    {
        return view('roles.index', ['store' => $this->context()]);
    }

    /** Every role the viewer can see from where they stand, with what the viewer may do to each. */
    public function data(): JsonResponse
    {
        $store = $this->context();
        $actor = auth()->user();

        // Inside a store only its own open invitations and members are counted — never another store's.
        $roles = ($store ? Role::availableInStore($store->id) : Role::query())
            ->with(['permissions:id,name,label', 'store:id,name'])
            ->withCount(['invitations as invitations_count' => fn ($query) => $query->when($store, fn ($query) => $query->where('store_id', $store->id))])
            ->get();

        $holders = DB::table('store_user')
            ->when($store, fn ($query) => $query->where('store_id', $store->id))
            ->groupBy('role_id')
            ->selectRaw('role_id, count(*) as holders')
            ->pluck('holders', 'role_id');

        $reach = $store ? $this->team->permissionsOf($actor, $store) : null;

        $rows = $roles
            ->sortBy(fn (Role $role) => [$this->listPosition($role), strtolower((string) $role->store?->name), strtolower($role->name)])
            ->map(function (Role $role) use ($store, $actor, $holders, $reach) {
                $held = (int) ($holders[$role->id] ?? 0);

                // Above the stores the super admin manages every role but Super-Admin, and deletes any but Super-Admin
                // and the Owner role. A role still held offers Delete too: deleting it says it must be unassigned first.
                if ($store === null) {
                    return $this->payload($role, $held,
                        canEdit: ! $role->isSuperAdmin(),
                        canDelete: ! $role->isSuperAdmin() && ! $role->isOwner(),
                    );
                }

                // Inside a store the store roles are read; the store's own custom roles are managed within reach.
                $withinReach = $role->isCustomRole() && $this->team->roleIsWithinReach($reach, $role);

                return $this->payload($role, $held,
                    canEdit: $withinReach && $actor->can('role-update'),
                    canDelete: $withinReach && $actor->can('role-destroy'),
                );
            });

        return response()->json(['roles' => $rows->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $store = $this->context();

        // Above the stores the super admin says what the role is for; inside a store it is that store's own.
        $type = $store ? 'custom' : $request->input('type');
        $validated = $this->validated($request, $store || in_array($type, self::TYPES, true) ? $type : null, $store, askType: $store === null);
        $permissions = $this->permissionsFor($validated['permissions'], $type, $store);

        $role = DB::transaction(function () use ($validated, $store, $type, $permissions) {
            $role = Role::create([
                'name' => $validated['name'],
                'store_id' => $store?->id,
                'is_global' => $type === 'platform',
                'created_by' => auth()->id(),
            ]);
            $role->permissions()->sync($permissions->pluck('id'));

            return $role;
        });

        ActivityLog::record('role.created', $role, match ($type) {
            'store' => "Created store role {$role->name}, offered in every store",
            'platform' => "Created platform role {$role->name}",
            default => "Created role {$role->name} in {$store->name}",
        });

        return response()->json(['message' => "Role {$role->name} created."], 201);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $store = $this->context();
        $type = $this->ensureManageable($role, $store);
        $roleStore = $role->isCustomRole() ? Store::findOrFail($role->store_id) : null;

        $validated = $this->validated($request, $type, $roleStore, editing: $role);
        $permissions = $this->permissionsFor($validated['permissions'], $type, $store);

        $before = $role->name;

        DB::transaction(function () use ($role, $validated, $permissions) {
            $role->update(['name' => $validated['name']]);
            $role->permissions()->sync($permissions->pluck('id'));
        });

        $renamed = $before !== $role->name ? " (was {$before})" : '';

        ActivityLog::record('role.updated', $role, match (true) {
            $type === 'store' => "Updated store role {$role->name}{$renamed}, in every store",
            $type === 'platform' => "Updated platform role {$role->name}{$renamed}",
            $store === null => "Updated role {$role->name}{$renamed} in {$roleStore->name}, from the platform",
            default => "Updated role {$role->name}{$renamed}",
        });

        return response()->json(['message' => $type === 'store' ? "Role {$role->name} updated in every store." : "Role {$role->name} updated."]);
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        $store = $this->context();
        $this->ensureManageable($role, $store, deleting: true);

        // Nobody may still hold it (owner's rule, 2026-09-17): the page offers Delete, and this says why not yet.
        $holders = $role->users()->count();
        if ($holders > 0) {
            return response()->json([
                'message' => "Please unassign {$role->name} from everyone first: {$holders} ".str('person')->plural($holders).' still '.($holders === 1 ? 'holds' : 'hold').' it.',
            ], 422);
        }

        $this->confirmPassword($request);

        // Open invitations to the role would go with it (the foreign key cascades): revoke them in the
        // open, so the log and the person deleting both say what happened to them.
        $name = $role->name;
        $storeId = $role->store_id;
        $invitations = DB::transaction(function () use ($role) {
            $revoked = Invitation::where('role_id', $role->id)->delete();
            $role->delete();

            return $revoked;
        });

        $revokedNote = $invitations > 0
            ? " {$invitations} pending ".str('invitation')->plural($invitations).' to it '.($invitations === 1 ? 'was' : 'were').' revoked.'
            : '';

        ActivityLog::record('role.deleted', null, "Deleted role {$name}.{$revokedNote}", storeId: $storeId);

        return response()->json(['message' => "Role {$name} deleted.{$revokedNote}"]);
    }

    /**
     * The permissions offered on the role form — only those the role can hold, nothing greyed out
     * (owner's rule, 2026-09-17). `?role=` names the role being edited; on the platform `?type=` says what a
     * new role is for. A store role or a custom role takes what works inside a store; a platform role
     * anything but the permission catalogue; and a member inside a store gives only what they hold there.
     */
    public function assignable(Request $request): JsonResponse
    {
        $store = $this->context();
        $editing = $request->filled('role') ? Role::find($request->integer('role')) : null;

        $type = match (true) {
            $store !== null => 'custom',
            $editing !== null => $this->typeOf($editing),
            default => $request->input('type') === 'platform' ? 'platform' : 'store',
        };

        $query = Permission::orderBy('name');

        if ($type === 'platform') {
            $query->whereNotIn('name', Permission::SUPER_ADMIN_ONLY);
        } else {
            $givable = collect($store ? $this->team->permissionsOf($request->user(), $store) : Permission::pluck('name'))
                ->filter(fn (string $name) => Permission::belongsToStores($name))
                ->values();

            $query->whereIn('name', $givable);
        }

        return response()->json($query->get(['id', 'name', 'label'])->map(fn (Permission $permission) => [
            'id' => $permission->id,
            'name' => $permission->name,
            'label' => $permission->display_name,
        ])->values());
    }

    /**
     * Where the viewer stands: their current store — or, for a super admin, the platform (null).
     * Anybody else (a platform role that is not Super-Admin) has no roles to manage.
     */
    private function context(): ?Store
    {
        $storeId = (int) session('current_store_id');
        $store = $storeId > 0 ? Store::find($storeId) : null;

        if ($store !== null && $this->team->isMember(auth()->user(), $store)) {
            return $store;
        }

        abort_unless(auth()->user()->isSuperAdmin(), 403, 'Roles are managed inside a store, or by a super admin.');

        return null;
    }

    /**
     * What may be changed from here, and what kind of role it is: above the stores, every role but
     * Super-Admin — the Owner role never deleted; inside a store, its own custom roles within reach.
     */
    private function ensureManageable(Role $role, ?Store $store, bool $deleting = false): string
    {
        abort_if($role->isSuperAdmin(), 403, 'The Super-Admin role always holds every permission, and is never changed.');

        if ($store === null) {
            abort_if($deleting && $role->isOwner(), 403, 'The Owner role is never deleted: it is how a store has an owner. Rename it or change what it allows instead.');

            return $this->typeOf($role);
        }

        abort_if($role->isStoreRole(), 403, 'Store roles are changed by the super admin, for every store at once.');
        abort_unless($role->isCustomRole() && $role->store_id === $store->id, 404);

        $actor = auth()->user();
        abort_unless(
            $this->team->roleIsWithinReach($this->team->permissionsOf($actor, $store), $role),
            403, 'You cannot change a role that has access you do not have.'
        );

        return 'custom';
    }

    private function typeOf(Role $role): string
    {
        return match (true) {
            $role->isGlobal() => 'platform',
            $role->isStoreRole() => 'store',
            default => 'custom',
        };
    }

    /**
     * The name and permissions — and, for a new role made on the platform, what it is for. A name has to be
     * new to every list the role appears in, compared with accents, case, spacing and punctuation folded
     * away: a store role is listed in every store, so no store's custom role may share its name, and a
     * custom role shares its store's list with the store roles. Nothing may pose as Super-Admin. A `$type` of
     * null — a new role whose type is missing or unknown — has no list yet: only the type is refused then.
     *
     * @return array{name: string, permissions: array<int, int>, type?: string}
     */
    private function validated(Request $request, ?string $type, ?Store $roleStore, ?Role $editing = null, bool $askType = false): array
    {
        return $request->validate([
            ...($askType ? ['type' => ['required', Rule::in(self::TYPES)]] : []),
            // `bail` because the closure below assumes the rules before it held: a name posted as an
            // array (name[]=x) would otherwise still reach it, and casting an array to a string is a 500.
            'name' => ['bail', 'required', 'string', 'max:255', function (string $attribute, mixed $value, \Closure $fail) use ($type, $roleStore, $editing) {
                $folded = self::foldName((string) $value);

                if ($folded === self::foldName(Role::SUPER_ADMIN)) {
                    $fail('That name belongs to the Super-Admin role. Choose another.');

                    return;
                }

                if ($type === null) {
                    return;
                }

                $clash = $this->rolesListedWith($type, $roleStore)
                    ->reject(fn (Role $role) => $editing !== null && $role->is($editing))
                    ->first(fn (Role $role) => self::foldName($role->name) === $folded);

                if ($clash !== null) {
                    $fail(match (true) {
                        $clash->isCustomRole() && $type === 'store' => "{$clash->store?->name} already has a custom role called {$clash->name}. Choose another name.",
                        $clash->isStoreRole() && $type === 'custom' => "There is already a store role called {$clash->name}, and every store has it. Choose another name.",
                        default => "There is already a role called {$clash->name}. Choose another name.",
                    });
                }
            }],
            ...self::PERMISSION_RULES,
        ], self::MESSAGES);
    }

    /**
     * The roles a role of this kind is listed with: a platform role with the platform team's; a store role
     * with every store role and every store's custom roles; a custom role with its store's list.
     *
     * @return Collection<int, Role>
     */
    private function rolesListedWith(string $type, ?Store $roleStore): Collection
    {
        return match ($type) {
            'platform' => Role::platform()->get(['id', 'name', 'is_global', 'store_id']),
            'store' => Role::where('is_global', false)->with('store:id,name')->get(['id', 'name', 'is_global', 'store_id']),
            default => Role::availableInStore($roleStore->id)->get(['id', 'name', 'is_global', 'store_id']),
        };
    }

    /**
     * A name the way a person reads it: accents, case, spacing and punctuation folded away, so
     * "Súper Admin" or "O-W-N-E-R" cannot pose as another role in a picker.
     */
    private static function foldName(string $name): string
    {
        return (string) Str::of($name)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '');
    }

    /**
     * The permission rows a role may carry (rules 7 and §3): the catalogue on no role but Super-Admin; a
     * store role or a custom role only what can work inside a store; and a member inside a store gives only
     * what they hold there themselves.
     *
     * @param  array<int, int>  $ids
     * @return Collection<int, Permission>
     */
    private function permissionsFor(array $ids, string $type, ?Store $memberStore): Collection
    {
        $permissions = Permission::whereIn('id', $ids)->get();

        if ($permissions->count() !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['permissions' => 'One of those permissions does not exist.']);
        }

        if ($permissions->contains(fn (Permission $permission) => in_array($permission->name, Permission::SUPER_ADMIN_ONLY, true))) {
            throw ValidationException::withMessages(['permissions' => 'Permission management belongs to the Super-Admin role alone.']);
        }

        $aboveTheStores = $type === 'platform'
            ? collect()
            : $permissions->reject(fn (Permission $permission) => Permission::belongsToStores($permission->name));

        if ($aboveTheStores->isNotEmpty()) {
            throw ValidationException::withMessages([
                'permissions' => 'A store role cannot hold '.$aboveTheStores->pluck('display_name')->join(', ', ' or ').': it works above the stores only.',
            ]);
        }

        if ($memberStore !== null && $permissions->pluck('name')->diff($this->team->permissionsOf(auth()->user(), $memberStore))->isNotEmpty()) {
            throw ValidationException::withMessages(['permissions' => 'You can only give a role permissions you hold yourself.']);
        }

        return $permissions;
    }

    /** Where a role sits in the list: Super-Admin, the Owner role, the store roles, the platform roles, then stores' custom roles. */
    private function listPosition(Role $role): int
    {
        return match (true) {
            $role->isSuperAdmin() => 0,
            $role->isOwner() => 1,
            $role->isStoreRole() => 2,
            $role->isGlobal() => 3,
            default => 4,
        };
    }

    /** @return array<string, mixed> */
    private function payload(Role $role, int $holders, bool $canEdit, bool $canDelete): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'kind' => $role->isSuperAdmin() ? 'super_admin' : $this->typeOf($role),
            'is_owner_role' => $role->isOwner(),
            'store_name' => $role->isCustomRole() ? $role->store?->name : null,
            'description' => $role->description(),
            'holders_count' => $holders,
            'invitations_count' => (int) ($role->invitations_count ?? 0),
            'permissions' => $role->permissions->map(fn (Permission $permission) => [
                'id' => $permission->id,
                'name' => $permission->name,
                'label' => $permission->display_name,
            ])->sortBy('name')->values(),
            'can_edit' => $canEdit,
            'can_delete' => $canDelete,
        ];
    }
}
