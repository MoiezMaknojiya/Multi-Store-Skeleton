<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
            {{ __('Profile Information') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            {{ __("Update your account's profile information and email address.") }}
        </p>
    </header>

    <form method="post" action="{{ route('profile.update') }}" class="mt-6 space-y-6"
        x-data="profileInfo()" @submit="validateBeforeSubmit($event)">
        @csrf
        @method('patch')

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
            <x-auth.form-field name="first_name" label="First Name" :required="true" :value="$user->first_name" autofocus autocomplete="given-name" />

            <x-auth.form-field name="last_name" label="Last Name" :required="true" :value="$user->last_name" autocomplete="family-name" />
        </div>

        {{-- The phone reaches Alpine through Js::from, never inside hand-written quotes (an apostrophe or a
             backslash would break the whole component), and only as one plain value: a flashed phone[]=x
             is an array, and an array here was a 500 on the profile page. --}}
        @php($phone = old('phone', $user->phone))
        <div x-data="{ phone: {{ Js::from(is_scalar($phone) ? (string) $phone : '') }} }">
            <x-auth.form-field name="phone" label="Phone (10 digits)" type="tel" :required="true" :value="$user->phone"
                placeholder="1234567890" x-model="phone" x-on:input="phone = phone.replace(/\D/g, '').slice(0, 10)" />
        </div>

        <x-auth.form-field name="email" label="Email" type="email" :required="true" :value="$user->email" autocomplete="username" />

        <div class="flex items-center gap-4">
            <x-primary-button>{{ __('Update') }}</x-primary-button>

            @if (session('status') === 'profile-updated')
                <p
                    x-show="show"
                    class="text-sm text-gray-600 dark:text-gray-400"
                >{{ __('Updated.') }}</p>
            @endif
        </div>
    </form>

    {{-- The email, confirmed by a link to it (owner's rule, 2026-09-29): a changed address waits here until its link
         is opened, and the account keeps the one it has meanwhile. --}}
    @if (session('error'))
        <p class="mt-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200"
           dusk="profile-email-error">{{ session('error') }}</p>
    @endif

    @if (session('status') === 'email-changed')
        <p class="mt-4 rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-900 dark:bg-green-950/40 dark:text-green-200"
           dusk="profile-email-changed">Your email is now {{ $user->email }}.</p>
    @elseif (session('status') === 'email-change-cancelled')
        <p class="mt-4 rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-900 dark:bg-green-950/40 dark:text-green-200"
           dusk="profile-email-kept">Your email stays {{ $user->email }}.</p>
    @elseif (session('status') === 'verification-link-sent')
        <p class="mt-4 rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-900 dark:bg-green-950/40 dark:text-green-200"
           dusk="profile-verification-sent">A new link is on its way to {{ $user->email }}. Open it to confirm your email.</p>
    @elseif (! $user->hasVerifiedEmail())
        {{-- Not confirmed yet: only this page and "Check your inbox" open until it is. --}}
        <div class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200"
             dusk="profile-email-unconfirmed">
            <p>
                Your email is not confirmed yet. Open the link we sent to
                <strong class="break-all">{{ $user->email }}</strong> to use the panel.
            </p>
            <form method="POST" action="{{ route('verification.send') }}" class="mt-3">
                @csrf
                <button type="submit" class="btn-secondary" dusk="profile-email-unconfirmed-resend">Send the link again</button>
            </form>
        </div>
    @endif

    @if ($user->pending_email)
        <div class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200"
             dusk="profile-email-pending">
            <p>
                We sent a link to <strong class="break-all">{{ $user->pending_email }}</strong>. Your email changes once
                you open it; until then it stays {{ $user->email }}.
            </p>
            @if (session('status') === 'email-link-resent')
                <p class="mt-2 font-medium" dusk="profile-email-resent">A new link is on its way.</p>
            @endif
            <div class="mt-3 flex flex-wrap items-center gap-3">
                <form method="POST" action="{{ route('profile.email.resend') }}">
                    @csrf
                    <button type="submit" class="btn-secondary" dusk="profile-email-resend">Send the link again</button>
                </form>
                <form method="POST" action="{{ route('profile.email.cancel') }}">
                    @csrf
                    @method('delete')
                    <button type="submit" class="btn-secondary" dusk="profile-email-cancel">Keep my current email</button>
                </form>
            </div>
        </div>
    @endif
</section>