{{-- "Your stores": the stores this person belongs to, with a way out of each (docs/STORE-ORGANIZATION-SPEC.md rule 10).
     It sits on Settings → Stores, and on the profile for whoever has no Stores tab (no View Stores where they work, or
     no store chosen) — leaving is open to every member whatever their role. Only a store's last Owner is held back.
     $canOpenStore (store-store, Settings → Stores only) adds the Create store button. --}}
<section>
    <header class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
        <div>
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ __('Your stores') }}</h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                {{ __('The stores you are a member of, and your role in each. Leaving removes your access straight away — to come back, someone in that store has to invite you again.') }}
            </p>
        </div>

        @if ($canOpenStore ?? false)
            <x-primary-button class="shrink-0" x-data x-on:click.prevent="$dispatch('open-modal', 'open-store')" dusk="open-store-button">
                {{ __('Create store') }}
            </x-primary-button>
        @endif
    </header>

    @if ($errors->storeMembership->isNotEmpty())
        <div class="mt-4 rounded-md border border-red-200 dark:border-red-900/50 bg-red-50 dark:bg-red-900/20 px-4 py-3 text-sm text-red-700 dark:text-red-300" role="alert">
            {{ $errors->storeMembership->first() }}
        </div>
    @endif

    @if ($memberships->isEmpty())
        <p class="mt-6 text-sm text-gray-500 dark:text-gray-400" dusk="no-store-memberships">{{ __('You are not a member of any store yet.') }}</p>
    @else
        <ul class="mt-6 divide-y divide-gray-100 dark:divide-gray-700 rounded-md border border-gray-200 dark:border-gray-700">
            @foreach ($memberships as $membership)
                @php($memberStore = $membership['store'])
                <li class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 px-4 py-3" dusk="store-membership-{{ $memberStore->id }}">
                    <div class="min-w-0">
                        <p class="font-medium text-gray-900 dark:text-gray-100 truncate">{{ $memberStore->name }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            {{ $membership['role']?->name ?? __('No role') }} · {{ $memberStore->city }}, {{ $memberStore->state }}
                        </p>
                    </div>

                    @if ($membership['is_sole_owner'])
                        <p class="text-xs text-gray-500 dark:text-gray-400 sm:text-right">{{ __('You are its only Owner — make someone else an Owner before you leave.') }}</p>
                    @else
                        <x-secondary-button class="shrink-0" x-data x-on:click.prevent="$dispatch('open-modal', 'leave-store-{{ $memberStore->id }}')"
                            dusk="leave-store-{{ $memberStore->id }}">
                            {{ __('Leave') }}
                        </x-secondary-button>
                    @endif
                </li>
            @endforeach
        </ul>

        @foreach ($memberships->reject(fn (array $membership) => $membership['is_sole_owner']) as $membership)
            @php($memberStore = $membership['store'])
            <x-modal name="leave-store-{{ $memberStore->id }}" :show="false" maxWidth="md" focusable>
                <form method="post" action="{{ route('profile.stores.leave', $memberStore) }}" class="p-6">
                    @csrf
                    @method('delete')

                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ __('Leave :store?', ['store' => $memberStore->name]) }}</h2>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                        {{ __('You lose access to this store straight away. What you added there stays with the store. To come back, someone in the store has to invite you again.') }}
                    </p>

                    <div class="mt-6 flex justify-end gap-3">
                        <x-secondary-button x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
                        <x-danger-button dusk="leave-store-{{ $memberStore->id }}-confirm">{{ __('Leave store') }}</x-danger-button>
                    </div>
                </form>
            </x-modal>
        @endforeach
    @endif
</section>
