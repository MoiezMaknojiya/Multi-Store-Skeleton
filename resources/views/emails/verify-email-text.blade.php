Thank you for signing up to {!! config('app.name') !!}.

Open this link to confirm that {!! $email !!} is your address:

{!! $url !!}

Once it is confirmed you can add your screens, upload your pictures and videos, and build your ads.

This link works for {{ $minutes }} minutes. If it has expired, sign in and ask for a new one.
An account that is not confirmed within {{ $days }} days is removed, with its store.
If you didn't sign up, ignore this email: nothing more will happen.

{!! config('app.name') !!}
{{-- Plain text, so every value is printed as it is: escaped, the link's "&" would read "&amp;" and break it. --}}
