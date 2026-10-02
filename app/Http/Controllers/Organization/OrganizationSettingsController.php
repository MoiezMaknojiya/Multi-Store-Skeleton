<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Concerns\ConfirmsPassword;
use App\Http\Controllers\Concerns\ResolvesCurrentOrganization;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Organization;
use App\Models\Role;
use App\Services\OrganizationTeam;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Settings → Organizations: the organization the person works in, and every organization they belong to (docs/ORGANIZATION-SPEC.md
 * rules 10, 22 and 33 — owner's rules, 2026-09-17). A role does not matter, its permissions do: the tab opens with
 * organization-view, and each card asks its own — the details form organization-update (read-only without it), Delete Organization
 * organization-destroy, a new organization organization-store. "Your organizations" lists every membership with a way out of it. An organization changes
 * hands on the Members page: whoever holds everything the Owner role allows gives it to another member.
 */
class OrganizationSettingsController extends Controller
{
    use ConfirmsPassword, ResolvesCurrentOrganization;

    /** The details an organization is described by, as the organization's own forms ask them. */
    private const DETAIL_LABELS = [
        'name' => 'organization name', 'street' => 'street', 'suite' => 'suite', 'city' => 'city',
        'state' => 'state', 'zip_code' => 'zip code', 'country' => 'country',
    ];

    public function __construct(private OrganizationTeam $team) {}

    public function edit(): View
    {
        $organization = $this->currentOrganization();
        $actor = auth()->user();
        $canDelete = $actor->can('organization-destroy');

        return view('organization-settings.edit', [
            'organization' => $organization,
            'states' => Organization::US_STATES,
            'canUpdate' => $actor->can('organization-update'),
            'canDelete' => $canDelete,
            'canOpenOrganization' => $actor->can('organization-store'),
            'memberships' => $this->team->membershipsOf($actor),
            'contents' => $canDelete ? [
                'members' => DB::table('organization_user')->where('organization_id', $organization->id)->count(),
                'screens' => $organization->screens()->count(),
                'media' => $organization->media()->count(),
            ] : [],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $organization = $this->currentOrganization();

        $validated = $request->validateWithBag('organizationDetails', self::detailRules(), self::detailMessages());

        $organization->update($validated);

        ActivityLog::record('organization.updated', $organization, "Updated the details of organization {$organization->name}");

        return redirect()->route('organization-settings.edit')->with('status', 'organization-updated');
    }

    /**
     * Open another organization — a new branch — and own it at once (organization-store): nobody is invited, and whether an organization
     * is active stays the platform's switch. The form's fields carry an organization_ prefix, so the details form on the same
     * page never reads a mistyped new organization back as its own.
     */
    public function openOrganization(Request $request): RedirectResponse
    {
        $this->currentOrganization();
        $actor = $request->user();

        $validated = $request->validateWithBag('newOrganization', self::detailRules('organization_'), self::detailMessages('organization_'), self::detailAttributes('organization_'));
        $details = collect($validated)->mapWithKeys(fn (mixed $value, string $key) => [substr($key, strlen('organization_')) => $value])->all();

        $newOrganization = DB::transaction(function () use ($details, $actor) {
            $newOrganization = Organization::create([...$details, 'is_active' => true, 'created_by' => $actor->id]);
            $actor->organizations()->attach($newOrganization->id, ['role_id' => Role::owner()->id]);

            return $newOrganization;
        });

        ActivityLog::record('organization.created', $newOrganization, "Opened organization {$newOrganization->name}, owned by {$actor->name}");

        return redirect()->route('organization-settings.edit')
            ->with('status', "{$newOrganization->name} is open, and you are its Owner. Switch to it from the organization menu.");
    }

    /**
     * Delete the organization and everything it owns (rule 22) — typed name and password first. The route asks for
     * organization-destroy in this organization: the Owner's by default, and whoever else the super admin gives it to.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $organization = $this->currentOrganization();

        // Not "name": the details form on the same page reads old('name'), and a mistyped
        // confirmation must never reappear there as the organization's new name.
        $request->validateWithBag('organizationDeletion', [
            'confirm_name' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail) use ($organization) {
                if ($value !== $organization->name) {
                    $fail('Type the organization name exactly as it is shown.');
                }
            }],
        ], [
            'confirm_name.required' => 'Type the organization name to confirm.',
        ]);

        $this->confirmPassword($request, 'organizationDeletion');

        $name = $organization->name;
        $screens = $organization->screens()->count();
        $media = $organization->media()->count();

        DB::transaction(fn () => $organization->delete());

        session()->forget('current_organization_id');

        ActivityLog::record('organization.deleted', null,
            "Deleted organization {$name} with its {$screens} ".str('screen')->plural($screens)." and {$media} media ".str('file')->plural($media),
            organizationId: $organization->id);

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
            "{$prefix}state" => ['required', 'string', 'size:2', Rule::in(array_keys(Organization::US_STATES))],
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
