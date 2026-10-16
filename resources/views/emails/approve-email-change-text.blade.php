Someone asked to change the sign-in email of your {!! config('app.name') !!} account to {!! $newEmail !!}.

If it was you, open this link to approve it. We then send a link to the new address, and the change is made once it is opened. Until then you keep signing in with {!! $email !!}.

{!! $url !!}

This link works for {{ $minutes }} minutes. If it has expired, ask for a new one from your profile.
If you didn't ask for this change, ignore this email: nothing changes. Then sign in and change your password, since someone may have your account open.

{!! config('app.name') !!}
{{-- Plain text, so every value is printed as it is: escaped, the link's "&" would read "&amp;" and break it. --}}
