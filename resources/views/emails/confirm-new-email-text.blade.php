Someone asked to change the email of a {!! config('app.name') !!} account to {!! $email !!}.

If it was you, open this link to confirm it. Until you do, the account keeps its old address.

{!! $url !!}

This link works for {{ $minutes }} minutes. If it has expired, ask for a new one from your profile.
If you didn't ask for this change, ignore this email: the account keeps its old address.

{!! config('app.name') !!}
{{-- Plain text, so every value is printed as it is: escaped, the link's "&" would read "&amp;" and break it. --}}
