{{-- Settings → Organizations (owner's rules, 2026-09-17): the organization the person works in and every organization they belong to, a tab
     beside the profile shown with View Organizations. Plain POST forms on the Profile track (x-auth.form-field). A role does not
     matter, its permissions do — each card renders for its own: the details form for organization-update (read-only without
     it), Create organization for organization-store, Delete Organization for organization-destroy (laid out like Delete Account) — and the routes
     refuse everyone else anyway. An organization changes hands on the Members page. --}}
<x-app-layout>
    <x-slot name="header">
        <h1 class="page-title">{{ __('Settings') }}</h1>
    </x-slot>

    {{-- The same frame and cards as every other page (it kept Breeze's padding and shadowed panels). --}}
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <x-settings-tabs active="organization" />

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
                {{-- Organization details --}}
                <div class="card p-4 sm:p-8">
                    <section>
                        <header>
                            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Organization Details</h2>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">The name and address of {{ $organization->name }}.</p>
                        </header>

                        @unless ($canUpdate)
                            <dl class="mt-6 grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm" dusk="organization-details-readonly">
                                <div>
                                    <dt class="text-gray-500 dark:text-gray-400">Organization name</dt>
                                    <dd class="mt-1 font-medium text-gray-900 dark:text-gray-100">{{ $organization->name }}</dd>
                                </div>
                                <div>
                                    <dt class="text-gray-500 dark:text-gray-400">Address</dt>
                                    <dd class="mt-1 text-gray-900 dark:text-gray-100">
                                        {{ collect([$organization->street, $organization->suite])->filter()->join(', ') }}<br>
                                        {{ $organization->city }}, {{ $organization->state }} {{ $organization->zip_code }} · {{ $organization->country }}
                                    </dd>
                                </div>
                            </dl>
                            <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">Your role here does not let you change the organization's details.</p>
                        @else
                        {{-- A container: the address rows go side by side only when the CARD is wide enough, since the
                             card shares the row with Your organizations on large screens. --}}
                        <form method="post" action="{{ route('organization-settings.update') }}" class="@container mt-6 space-y-6"
                            x-data="organizationDetailsForm()" @submit="validateBeforeSubmit($event)" dusk="organization-details-form">
                            @csrf
                            @method('put')

                            <x-auth.form-field name="name" label="Organization name" bag="organizationDetails" :value="$organization->name" :required="true" maxlength="255" autocomplete="organization" />

                            <div class="grid grid-cols-1 @lg:grid-cols-3 gap-6">
                                <div class="@lg:col-span-2">
                                    <x-auth.form-field name="street" label="Street" bag="organizationDetails" :value="$organization->street" :required="true" maxlength="255" autocomplete="address-line1" />
                                </div>
                                <x-auth.form-field name="suite" label="Suite / Unit" bag="organizationDetails" :value="$organization->suite" maxlength="100" autocomplete="address-line2" />
                            </div>

                            <div class="grid grid-cols-1 @lg:grid-cols-3 gap-6">
                                <x-auth.form-field name="city" label="City" bag="organizationDetails" :value="$organization->city" :required="true" maxlength="100" autocomplete="address-level2" />

                                <x-auth.form-field name="state" label="State" bag="organizationDetails" :required="true">
                                    <select id="state" name="state" class="form-select {{ $errors->organizationDetails->has('state') ? '!border-red-500' : '' }}">
                                        <option value="">Select state</option>
                                        @foreach ($states as $code => $stateName)
                                            <option value="{{ $code }}" @selected(old('state', $organization->state) === $code)>{{ $stateName }} ({{ $code }})</option>
                                        @endforeach
                                    </select>
                                </x-auth.form-field>

                                <x-auth.form-field name="zip_code" label="Zip code" bag="organizationDetails" :value="$organization->zip_code" :required="true" data-digits="10" inputmode="numeric" autocomplete="postal-code" />
                            </div>

                            <x-auth.form-field name="country" label="Country" bag="organizationDetails" :value="$organization->country" :required="true" maxlength="100" autocomplete="country-name" />

                            {{-- "Organization details saved." arrives as a toast (components/toasts.blade.php). --}}
                            <div class="flex items-center gap-4">
                                <x-primary-button dusk="organization-details-save">{{ __('Save Changes') }}</x-primary-button>
                            </div>
                        </form>
                        @endunless
                    </section>
                </div>

                {{-- Your organizations: every organization the person belongs to, with a way out — and Create organization for organization-store --}}
                <div class="card p-4 sm:p-8" dusk="your-organizations">
                    @include('profile.partials.organization-memberships', ['canOpenOrganization' => $canOpenOrganization])
                </div>
            </div>

            @if ($canDelete)
                {{-- Delete organization: laid out like Delete Account on the Profile tab --}}
                <div class="card p-4 sm:p-8" dusk="delete-organization">
                    <section class="max-w-xl space-y-6">
                        <header>
                            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Delete Organization</h2>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                Permanently deletes {{ $organization->name }} and everything in it:
                                {{ $contents['screens'] }} {{ str('screen')->plural($contents['screens']) }} (they stop playing at once),
                                {{ $contents['media'] }} media {{ str('file')->plural($contents['media']) }}, playlists, dayparts, the roles made in it,
                                the organization's own channels, its Ad Builder designs and the assets they are made from, open invitations,
                                and the access of all {{ $contents['members'] }} {{ str('member')->plural($contents['members']) }}.
                                The people's accounts are not deleted.
                            </p>
                        </header>

                        <x-danger-button x-data x-on:click.prevent="$dispatch('open-modal', 'confirm-organization-deletion')" dusk="delete-organization-button">
                            {{ __('Delete Organization') }}
                        </x-danger-button>
                    </section>
                </div>
            @endif

            @if ($canOpenOrganization)
                {{-- Create organization: a new organization the person owns at once. The organization_ names keep it apart from the details form. --}}
                <x-modal name="open-organization" :show="$errors->newOrganization->isNotEmpty()" maxWidth="2xl" focusable>
                    <form method="post" action="{{ route('organization-settings.open') }}" class="p-6 space-y-5"
                        x-data="openOrganizationForm()" @submit="validateBeforeSubmit($event)" dusk="open-organization-form">
                        @csrf

                        <div>
                            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ __('Create Organization') }}</h2>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                You'll be its Owner.
                            </p>
                        </div>

                        <x-auth.form-field name="organization_name" label="Organization name" bag="newOrganization" :required="true" maxlength="255" autocomplete="off" dusk="open-organization-name" />

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div class="sm:col-span-2">
                                <x-auth.form-field name="organization_street" label="Street" bag="newOrganization" :required="true" maxlength="255" autocomplete="off" dusk="open-organization-street" />
                            </div>
                            <x-auth.form-field name="organization_suite" label="Suite / Unit" bag="newOrganization" maxlength="100" autocomplete="off" dusk="open-organization-suite" />
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <x-auth.form-field name="organization_city" label="City" bag="newOrganization" :required="true" maxlength="100" autocomplete="off" dusk="open-organization-city" />

                            <x-auth.form-field name="organization_state" label="State" bag="newOrganization" :required="true">
                                <select id="organization_state" name="organization_state" dusk="open-organization-state"
                                    class="form-select {{ $errors->newOrganization->has('organization_state') ? '!border-red-500' : '' }}">
                                    <option value="">Select state</option>
                                    @foreach ($states as $code => $stateName)
                                        <option value="{{ $code }}" @selected(old('organization_state') === $code)>{{ $stateName }} ({{ $code }})</option>
                                    @endforeach
                                </select>
                            </x-auth.form-field>

                            <x-auth.form-field name="organization_zip_code" label="Zip code" bag="newOrganization" :required="true" data-digits="10" inputmode="numeric" autocomplete="off" dusk="open-organization-zip" />
                        </div>

                        <x-auth.form-field name="organization_country" label="Country" bag="newOrganization" value="USA" :required="true" maxlength="100" autocomplete="off" dusk="open-organization-country" />

                        <div class="flex flex-wrap justify-end gap-3">
                            <x-secondary-button x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
                            <x-primary-button dusk="open-organization-confirm">{{ __('Create Organization') }}</x-primary-button>
                        </div>
                    </form>
                </x-modal>
            @endif

            @if ($canDelete)
                {{-- Delete organization --}}
                <x-modal name="confirm-organization-deletion" :show="$errors->organizationDeletion->isNotEmpty()" focusable>
                    <form method="post" action="{{ route('organization-settings.destroy') }}" class="p-6 space-y-5"
                        x-data="deleteOrganizationForm({{ Js::from($organization->name) }})" @submit="validateBeforeSubmit($event)" dusk="delete-organization-form">
                        @csrf
                        @method('delete')

                        <div>
                            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Are you sure you want to delete {{ $organization->name }}?</h2>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                The organization and everything in it are removed for good, and its screens stop playing immediately.
                                <span class="font-semibold text-red-600 dark:text-red-400">It cannot be undone.</span>
                            </p>
                        </div>

                        {{-- The name is handed over on its own (highlight), never echoed into label="": the component
                             escapes it once and picks it out where the label says :name, so "Joe's" reads "Joe's". --}}
                        <x-auth.form-field name="confirm_name" bag="organizationDeletion" :required="true"
                            label="Type :name to confirm" :highlight="$organization->name" maxlength="255" autocomplete="off" dusk="delete-organization-name" />

                        <x-auth.form-field name="password" id="delete_password" label="Your password" type="password" bag="organizationDeletion"
                            :required="true" autocomplete="current-password" dusk="delete-organization-password" />

                        <div class="flex flex-wrap justify-end gap-3">
                            <x-secondary-button x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
                            <x-danger-button dusk="delete-organization-confirm">{{ __('Delete Organization') }}</x-danger-button>
                        </div>
                    </form>
                </x-modal>
            @endif
    </div>
</x-app-layout>
