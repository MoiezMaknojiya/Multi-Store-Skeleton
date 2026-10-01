{{-- "Your organizations": the organizations this person belongs to, with a way out of each (docs/ORGANIZATION-SPEC.md rule 10).
     It sits on Settings → Organizations, and on the profile for whoever has no Organizations tab (no View Organizations where they work, or
     no organization chosen) — leaving is open to every member whatever their role. Only an organization's last Owner is held back.
     $canOpenOrganization (organization-store, Settings → Organizations only) adds the Create organization button. --}}
<section>
    <header class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
        <div>
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ __('Your organizations') }}</h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                {{ __('Your organizations and your role in each.') }}
            </p>
        </div>

        @if ($canOpenOrganization ?? false)
            {{-- Secondary: this card's own action stands beside the page's Save changes, which is the primary. --}}
            <x-secondary-button class="shrink-0" x-data x-on:click.prevent="$dispatch('open-modal', 'open-organization')" dusk="open-organization-button">
                {{ __('Create Organization') }}
            </x-secondary-button>
        @endif
    </header>

    @if ($errors->organizationMembership->isNotEmpty())
        <div class="alert-error mt-4" role="alert">
            {{ $errors->organizationMembership->first() }}
        </div>
    @endif

    @if ($memberships->isEmpty())
        <p class="mt-6 text-sm text-gray-500 dark:text-gray-400" dusk="no-organization-memberships">{{ __('You are not a member of any organization yet.') }}</p>
    @else
        <ul class="mt-6 divide-y divide-gray-100 dark:divide-gray-700 rounded-md border border-gray-200 dark:border-gray-700">
            @foreach ($memberships as $membership)
                @php($memberOrganization = $membership['organization'])
                <li class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 px-4 py-3" dusk="organization-membership-{{ $memberOrganization->id }}">
                    <div class="min-w-0">
                        <p class="font-medium text-gray-900 dark:text-gray-100 truncate" title="{{ $memberOrganization->name }}">{{ $memberOrganization->name }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            {{ $membership['role']?->name ?? __('No role') }} · {{ $memberOrganization->city }}, {{ $memberOrganization->state }}
                        </p>
                    </div>

                    @if ($membership['is_sole_owner'])
                        <p class="text-xs text-gray-500 dark:text-gray-400 sm:text-right">{{ __('You are its only Owner — make someone else an Owner before you leave.') }}</p>
                    @else
                        <x-secondary-button class="shrink-0" x-data x-on:click.prevent="$dispatch('open-modal', 'leave-organization-{{ $memberOrganization->id }}')"
                            dusk="leave-organization-{{ $memberOrganization->id }}">
                            {{ __('Leave') }}
                        </x-secondary-button>
                    @endif
                </li>
            @endforeach
        </ul>

        @foreach ($memberships->reject(fn (array $membership) => $membership['is_sole_owner']) as $membership)
            @php($memberOrganization = $membership['organization'])
            <x-modal name="leave-organization-{{ $memberOrganization->id }}" :show="false" maxWidth="md" focusable>
                <form method="post" action="{{ route('profile.organizations.leave', $memberOrganization) }}" class="p-6">
                    @csrf
                    @method('delete')

                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ __('Leave :organization?', ['organization' => $memberOrganization->name]) }}</h2>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                        {{ __('You lose access to this organization straight away. What you added there stays with the organization. To come back, someone in the organization has to invite you again.') }}
                    </p>

                    <div class="mt-6 flex flex-wrap justify-end gap-3">
                        <x-secondary-button x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
                        <x-danger-button dusk="leave-organization-{{ $memberOrganization->id }}-confirm">{{ __('Leave Organization') }}</x-danger-button>
                    </div>
                </form>
            </x-modal>
        @endforeach
    @endif
</section>
