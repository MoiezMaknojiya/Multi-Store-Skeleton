{{-- A signed-in person who is not a member of any organization yet. An invitation to their own confirmed address
     shows above this, in "Invitations for you" (dashboard.partials.invitations), and in their inbox. --}}
<div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
<div class="card p-10 text-center" dusk="dashboard-empty">
    <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-blue-50 dark:bg-blue-900/30 mb-5">
        <svg class="w-8 h-8 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
        </svg>
    </div>
    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">You're not a member of any organization yet</h2>
    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400 max-w-md mx-auto">
        Ask an organization's owner to invite {{ auth()->user()->email }}. The invitation shows up here and in that inbox.
    </p>
    {{-- The one thing to check from here: the address an owner has to invite. --}}
    <a href="{{ route('profile.edit') }}" class="btn-secondary mt-6" dusk="dashboard-empty-settings">Check Your Email in Settings</a>
</div>
</div>
