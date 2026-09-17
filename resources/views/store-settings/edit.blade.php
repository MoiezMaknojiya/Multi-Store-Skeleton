{{-- Settings → Stores (owner's rules, 2026-09-17): the store the person works in and every store they belong to, a tab
     beside the profile shown with View Stores. Plain POST forms on the Profile track (x-auth.form-field). A role does not
     matter, its permissions do — each card renders for its own: the details form for store-update (read-only without
     it), Create store for store-store, Delete Store for store-destroy (laid out like Delete Account) — and the routes
     refuse everyone else anyway. A store changes hands on the Members page. --}}
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('Settings') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <x-settings-tabs active="store" />

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
                {{-- Store details --}}
                <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                    <section>
                        <header>
                            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Store Details</h2>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">The name and address of {{ $store->name }}.</p>
                        </header>

                        @unless ($canUpdate)
                            <dl class="mt-6 grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm" dusk="store-details-readonly">
                                <div>
                                    <dt class="text-gray-500 dark:text-gray-400">Store name</dt>
                                    <dd class="mt-1 font-medium text-gray-900 dark:text-gray-100">{{ $store->name }}</dd>
                                </div>
                                <div>
                                    <dt class="text-gray-500 dark:text-gray-400">Address</dt>
                                    <dd class="mt-1 text-gray-900 dark:text-gray-100">
                                        {{ collect([$store->street, $store->suite])->filter()->join(', ') }}<br>
                                        {{ $store->city }}, {{ $store->state }} {{ $store->zip_code }} · {{ $store->country }}
                                    </dd>
                                </div>
                            </dl>
                            <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">Your role here does not let you change the store's details.</p>
                        @else
                        {{-- A container: the address rows go side by side only when the CARD is wide enough, since the
                             card shares the row with Your stores on large screens. --}}
                        <form method="post" action="{{ route('store-settings.update') }}" class="@container mt-6 space-y-6"
                            x-data="storeDetailsForm()" @submit="validateBeforeSubmit($event)" dusk="store-details-form">
                            @csrf
                            @method('put')

                            <x-auth.form-field name="name" label="Store name" bag="storeDetails" :value="$store->name" :required="true" autocomplete="organization" />

                            <div class="grid grid-cols-1 @lg:grid-cols-3 gap-6">
                                <div class="@lg:col-span-2">
                                    <x-auth.form-field name="street" label="Street" bag="storeDetails" :value="$store->street" :required="true" autocomplete="address-line1" />
                                </div>
                                <x-auth.form-field name="suite" label="Suite / Unit" bag="storeDetails" :value="$store->suite" autocomplete="address-line2" />
                            </div>

                            <div class="grid grid-cols-1 @lg:grid-cols-3 gap-6">
                                <x-auth.form-field name="city" label="City" bag="storeDetails" :value="$store->city" :required="true" autocomplete="address-level2" />

                                <x-auth.form-field name="state" label="State" bag="storeDetails" :required="true">
                                    <select id="state" name="state" class="form-select {{ $errors->storeDetails->has('state') ? '!border-red-500' : '' }}">
                                        <option value="">Select state</option>
                                        @foreach ($states as $code => $stateName)
                                            <option value="{{ $code }}" @selected(old('state', $store->state) === $code)>{{ $stateName }} ({{ $code }})</option>
                                        @endforeach
                                    </select>
                                </x-auth.form-field>

                                <x-auth.form-field name="zip_code" label="Zip code" bag="storeDetails" :value="$store->zip_code" :required="true" inputmode="numeric" autocomplete="postal-code" />
                            </div>

                            <x-auth.form-field name="country" label="Country" bag="storeDetails" :value="$store->country" :required="true" autocomplete="country-name" />

                            <div class="flex items-center gap-4">
                                <x-primary-button dusk="store-details-save">{{ __('Save changes') }}</x-primary-button>

                                @if (session('status') === 'store-updated')
                                    <p x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 2500)"
                                        class="text-sm text-gray-600 dark:text-gray-400">{{ __('Saved.') }}</p>
                                @endif
                            </div>
                        </form>
                        @endunless
                    </section>
                </div>

                {{-- Your stores: every store the person belongs to, with a way out — and Create store for store-store --}}
                <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg" dusk="your-stores">
                    @include('profile.partials.store-memberships', ['canOpenStore' => $canOpenStore])
                </div>
            </div>

            @if ($canDelete)
                {{-- Delete store: laid out like Delete Account on the Profile tab --}}
                <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg" dusk="delete-store">
                    <section class="max-w-xl space-y-6">
                        <header>
                            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Delete Store</h2>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                Permanently deletes {{ $store->name }} and everything in it:
                                {{ $contents['screens'] }} {{ str('screen')->plural($contents['screens']) }} (they stop playing at once),
                                {{ $contents['media'] }} media {{ str('file')->plural($contents['media']) }}, playlists, dayparts, the roles made in it,
                                the store's own channels, open invitations, and the access of all {{ $contents['members'] }} {{ str('member')->plural($contents['members']) }}.
                                The people's accounts are not deleted.
                            </p>
                        </header>

                        <x-danger-button x-data x-on:click.prevent="$dispatch('open-modal', 'confirm-store-deletion')" dusk="delete-store-button">
                            {{ __('Delete Store') }}
                        </x-danger-button>
                    </section>
                </div>
            @endif

            @if ($canOpenStore)
                {{-- Create store: a new store the person owns at once. The store_ names keep it apart from the details form. --}}
                <x-modal name="open-store" :show="$errors->newStore->isNotEmpty()" maxWidth="2xl" focusable>
                    <form method="post" action="{{ route('store-settings.open') }}" class="p-6 space-y-5"
                        x-data="openStoreForm()" @submit="validateBeforeSubmit($event)" dusk="open-store-form">
                        @csrf

                        <div>
                            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ __('Create store') }}</h2>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                You will be the Owner of the new store. Switch to it from the store menu to set it up.
                            </p>
                        </div>

                        <x-auth.form-field name="store_name" label="Store name" bag="newStore" :required="true" autocomplete="off" dusk="open-store-name" />

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div class="sm:col-span-2">
                                <x-auth.form-field name="store_street" label="Street" bag="newStore" :required="true" autocomplete="off" dusk="open-store-street" />
                            </div>
                            <x-auth.form-field name="store_suite" label="Suite / Unit" bag="newStore" autocomplete="off" dusk="open-store-suite" />
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <x-auth.form-field name="store_city" label="City" bag="newStore" :required="true" autocomplete="off" dusk="open-store-city" />

                            <x-auth.form-field name="store_state" label="State" bag="newStore" :required="true">
                                <select id="store_state" name="store_state" dusk="open-store-state"
                                    class="form-select {{ $errors->newStore->has('store_state') ? '!border-red-500' : '' }}">
                                    <option value="">Select state</option>
                                    @foreach ($states as $code => $stateName)
                                        <option value="{{ $code }}" @selected(old('store_state') === $code)>{{ $stateName }} ({{ $code }})</option>
                                    @endforeach
                                </select>
                            </x-auth.form-field>

                            <x-auth.form-field name="store_zip_code" label="Zip code" bag="newStore" :required="true" inputmode="numeric" autocomplete="off" dusk="open-store-zip" />
                        </div>

                        <x-auth.form-field name="store_country" label="Country" bag="newStore" value="USA" :required="true" autocomplete="off" dusk="open-store-country" />

                        <div class="flex justify-end gap-3">
                            <x-secondary-button x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
                            <x-primary-button dusk="open-store-confirm">{{ __('Create store') }}</x-primary-button>
                        </div>
                    </form>
                </x-modal>
            @endif

            @if ($canDelete)
                {{-- Delete store --}}
                <x-modal name="confirm-store-deletion" :show="$errors->storeDeletion->isNotEmpty()" focusable>
                    <form method="post" action="{{ route('store-settings.destroy') }}" class="p-6 space-y-5"
                        x-data="deleteStoreForm({{ Js::from($store->name) }})" @submit="validateBeforeSubmit($event)" dusk="delete-store-form">
                        @csrf
                        @method('delete')

                        <div>
                            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Are you sure you want to delete {{ $store->name }}?</h2>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                The store and everything in it are removed for good, and its screens stop playing immediately.
                                <span class="font-semibold text-red-600 dark:text-red-400">It cannot be undone.</span>
                            </p>
                        </div>

                        <x-auth.form-field name="confirm_name" id="confirm_name" bag="storeDeletion" :required="true"
                            label="Type {{ $store->name }} to confirm" autocomplete="off" dusk="delete-store-name" />

                        <x-auth.form-field name="password" id="delete_password" label="Your password" type="password" bag="storeDeletion"
                            :required="true" autocomplete="current-password" dusk="delete-store-password" />

                        <div class="flex justify-end gap-3">
                            <x-secondary-button x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
                            <x-danger-button dusk="delete-store-confirm">{{ __('Delete Store') }}</x-danger-button>
                        </div>
                    </form>
                </x-modal>
            @endif
        </div>
    </div>
</x-app-layout>
