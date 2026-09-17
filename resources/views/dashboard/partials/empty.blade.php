{{-- A signed-in person who is not a member of any store yet. Deliberately no list of pending
     invitations with Accept buttons: public signup does not verify the email, so only the emailed
     link — which proves the inbox — may accept (docs/STORE-ORGANIZATION-SPEC.md §8). --}}
<div class="bg-white dark:bg-gray-800 rounded-xs border border-gray-100 dark:border-gray-700 p-10 text-center" dusk="dashboard-empty">
    <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-blue-50 dark:bg-blue-900/30 mb-5">
        <svg class="w-8 h-8 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
        </svg>
    </div>
    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">You're not a member of any store yet</h3>
    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400 max-w-md mx-auto">
        Invitations arrive by email. Ask a store's owner to invite {{ auth()->user()->email }}, then open the link in that email.
    </p>
</div>
