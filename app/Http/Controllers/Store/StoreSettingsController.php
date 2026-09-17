<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Concerns\ConfirmsPassword;
use App\Http\Controllers\Concerns\ResolvesCurrentStore;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\Store;
use App\Services\StoreTeam;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Settings → Stores: the store the person works in, and every store they belong to (docs/STORE-ORGANIZATION-SPEC.md
 * rules 10, 22 and 33 — owner's rules, 2026-09-17). A role does not matter, its permissions do: the tab opens with
 * store-view, and each card asks its own — the details form store-update (read-only without it), Delete Store
 * store-destroy, a new store store-store. "Your stores" lists every membership with a way out of it. A store changes
 * hands on the Members page: whoever holds everything the Owner role allows gives it to another member.
 */
class StoreSettingsController extends Controller
{
    use ConfirmsPassword, ResolvesCurrentStore;

    /** The details a store is described by, as the store's own forms ask them. */
    private const DETAIL_LABELS = [
        'name' => 'store name', 'street' => 'street', 'suite' => 'suite', 'city' => 'city',
        'state' => 'state', 'zip_code' => 'zip code', 'country' => 'country',
    ];

    public function __construct(private StoreTeam $team) {}

    public function edit(): View
    {
        $store = $this->currentStore();
        $actor = auth()->user();
        $canDelete = $actor->can('store-destroy');

        return view('store-settings.edit', [
            'store' => $store,
            'states' => Store::US_STATES,
            'canUpdate' => $actor->can('store-update'),
            'canDelete' => $canDelete,
            'canOpenStore' => $actor->can('store-store'),
            'memberships' => $this->team->membershipsOf($actor),
            'contents' => $canDelete ? [
                'members' => DB::table('store_user')->where('store_id', $store->id)->count(),
                'screens' => $store->screens()->count(),
                'media' => $store->media()->count(),
            ] : [],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $store = $this->currentStore();

        $validated = $request->validateWithBag('storeDetails', self::detailRules(), self::detailMessages());

        $store->update($validated);

        ActivityLog::record('store.updated', $store, "Updated the details of store {$store->name}");

        return redirect()->route('store-settings.edit')->with('status', 'store-updated');
    }

    /**
     * Open another store — a new branch — and own it at once (store-store): nobody is invited, and whether a store
     * is active stays the platform's switch. The form's fields carry a store_ prefix, so the details form on the same
     * page never reads a mistyped new store back as its own.
     */
    public function openStore(Request $request): RedirectResponse
    {
        $this->currentStore();
        $actor = $request->user();

        $validated = $request->validateWithBag('newStore', self::detailRules('store_'), self::detailMessages('store_'), self::detailAttributes('store_'));
        $details = collect($validated)->mapWithKeys(fn (mixed $value, string $key) => [substr($key, strlen('store_')) => $value])->all();

        $newStore = DB::transaction(function () use ($details, $actor) {
            $newStore = Store::create([...$details, 'is_active' => true, 'created_by' => $actor->id]);
            $actor->stores()->attach($newStore->id, ['role_id' => Role::owner()->id]);

            return $newStore;
        });

        ActivityLog::record('store.created', $newStore, "Opened store {$newStore->name}, owned by {$actor->name}");

        return redirect()->route('store-settings.edit')
            ->with('status', "{$newStore->name} is open, and you are its Owner. Switch to it from the store menu.");
    }

    /**
     * Delete the store and everything it owns (rule 22) — typed name and password first. The route asks for
     * store-destroy in this store: the Owner's by default, and whoever else the super admin gives it to.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $store = $this->currentStore();

        // Not "name": the details form on the same page reads old('name'), and a mistyped
        // confirmation must never reappear there as the store's new name.
        $request->validateWithBag('storeDeletion', [
            'confirm_name' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail) use ($store) {
                if ($value !== $store->name) {
                    $fail('Type the store name exactly as it is shown.');
                }
            }],
        ], [
            'confirm_name.required' => 'Type the store name to confirm.',
        ]);

        $this->confirmPassword($request, 'storeDeletion');

        $name = $store->name;
        $screens = $store->screens()->count();
        $media = $store->media()->count();

        DB::transaction(fn () => $store->delete());

        session()->forget('current_store_id');

        ActivityLog::record('store.deleted', null,
            "Deleted store {$name} with its {$screens} ".str('screen')->plural($screens)." and {$media} media ".str('file')->plural($media),
            storeId: $store->id);

        return redirect()->route('dashboard')->with('status', "{$name} was deleted.");
    }

    /** @return array<string, array<int, mixed>> */
    private static function detailRules(string $prefix = ''): array
    {
        return [
            "{$prefix}name" => ['required', 'string', 'max:255'],
            "{$prefix}street" => ['required', 'string', 'max:255'],
            "{$prefix}suite" => ['nullable', 'string', 'max:100'],
            "{$prefix}city" => ['required', 'string', 'max:100'],
            "{$prefix}state" => ['required', 'string', 'size:2', Rule::in(array_keys(Store::US_STATES))],
            "{$prefix}zip_code" => ['required', 'string', 'max:10', 'regex:/^[0-9]+$/'],
            "{$prefix}country" => ['required', 'string', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    private static function detailMessages(string $prefix = ''): array
    {
        return ["{$prefix}zip_code.regex" => 'Zip code can only contain numbers.'];
    }

    /** @return array<string, string> */
    private static function detailAttributes(string $prefix): array
    {
        return collect(self::DETAIL_LABELS)->mapWithKeys(fn (string $label, string $field) => ["{$prefix}{$field}" => $label])->all();
    }
}
