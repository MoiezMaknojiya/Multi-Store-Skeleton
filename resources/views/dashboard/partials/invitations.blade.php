{{-- The organizations' invitations waiting for this account (owner, 2026-10-07 — "haan bana do, decline par sirf log,
     dashboard card kaafi ha"): sent to its own confirmed address and still open (Invitation::sentTo, stillOpen). Accept joins
     at once, as the emailed link would; Decline asks first, then the invitation goes and only the log says so — nobody is
     emailed. The platform team's invitations are answered by their emailed link alone, so a platform account never sees this. --}}
<div class="max-w-7xl mx-auto sm:px-6 lg:px-8 mb-6">
    <section class="card" aria-labelledby="dashboard-invitations-title" dusk="dashboard-invitations">
        <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-gray-100 dark:border-gray-700">
            <h2 id="dashboard-invitations-title" class="text-base font-semibold text-gray-800 dark:text-white">Invitations for you</h2>
            <span class="badge-info" dusk="dashboard-invitations-count">{{ $invitations->count() }}<span class="sr-only"> waiting</span></span>
        </div>

        <ul class="divide-y divide-gray-100 dark:divide-gray-700">
            @foreach ($invitations as $invitation)
                <li class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between" dusk="dashboard-invitation-{{ $invitation->id }}">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-gray-800 dark:text-gray-100 break-words">
                            {{ $invitation->organization->name }} invited you as {{ $invitation->role->name }}
                        </p>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                            @if ($invitation->inviter)
                                Sent by {{ $invitation->inviter->name }} ·
                            @endif
                            Expires {{ $invitation->expires_at->diffForHumans() }}
                        </p>
                    </div>

                    <div class="flex shrink-0 flex-wrap items-center gap-2">
                        <form method="post" action="{{ route('dashboard.invitations.accept', $invitation->id) }}">
                            @csrf
                            <x-primary-button aria-label="Accept the Invitation to {{ $invitation->organization->name }}" dusk="accept-invitation-{{ $invitation->id }}">
                                Accept
                            </x-primary-button>
                        </form>
                        <x-secondary-button x-data x-on:click.prevent="$dispatch('open-modal', 'decline-invitation-{{ $invitation->id }}')"
                            aria-label="Decline the Invitation to {{ $invitation->organization->name }}" dusk="decline-invitation-{{ $invitation->id }}">
                            Decline
                        </x-secondary-button>
                    </div>
                </li>
            @endforeach
        </ul>
    </section>

    @foreach ($invitations as $invitation)
        <x-modal name="decline-invitation-{{ $invitation->id }}" :show="false" maxWidth="md" focusable>
            <form method="post" action="{{ route('dashboard.invitations.decline', $invitation->id) }}" class="p-6">
                @csrf

                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100 break-words">Decline the invitation to {{ $invitation->organization->name }}?</h2>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                    The invitation goes, and nobody is emailed. To join later, someone in {{ $invitation->organization->name }} has to invite you again.
                </p>

                <div class="mt-6 flex flex-wrap justify-end gap-3">
                    <x-secondary-button x-on:click="$dispatch('close')" dusk="decline-invitation-{{ $invitation->id }}-cancel">Cancel</x-secondary-button>
                    <x-danger-button dusk="decline-invitation-{{ $invitation->id }}-confirm">Decline Invitation</x-danger-button>
                </div>
            </form>
        </x-modal>
    @endforeach
</div>
