{{-- "Log in as" (ImpersonateController): while a super admin is viewing the app as somebody else, every
     page says so and offers the way back. Shared by both shells a signed-in person sees — the app layout
     and the focused layout — because "Log in as" a member of several stores lands on the store picker
     first, and the way back has to be there too. --}}
@if (session('impersonating_original_id'))
    <div class="flex-shrink-0 flex items-center justify-between gap-4 px-4 sm:px-6 py-2 bg-amber-500 text-white text-sm">
        <span>You are viewing as <strong>{{ auth()->user()->name }}</strong> ({{ auth()->user()->email }}).</span>
        <form method="POST" action="{{ route('impersonate.stop') }}">
            @csrf
            <button type="submit" class="font-semibold underline hover:no-underline whitespace-nowrap">
                Return to Super Admin
            </button>
        </form>
    </div>
@endif
