{!! $name ? 'Hello '.$name.' — we received a request to reset the password for '.$email.'.' : 'We received a request to reset the password for '.$email.'.' !!}

Open this link to choose a new one:

{!! $url !!}

This link expires in {{ $minutes }} minutes and can only be used once.
If you didn't ask for it, nothing has changed — your password stays as it is and you can ignore this email.

{!! config('app.name') !!}
{{-- Plain text, so every value is printed as it is: escaped, a name like O'Brien read "O&#039;Brien". --}}
