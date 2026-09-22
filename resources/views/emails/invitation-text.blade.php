{{ $invitedBy ? $invitedBy.' has invited you to join '.$place.' as '.$role.'.' : 'You have been invited to join '.$place.' as '.$role.'.' }}

Open this link to accept. If you don't have an account yet, you'll set your password on the same page:

{{ $url }}

This invitation expires on {{ $expires }}, and the link can only be used once.
If you weren't expecting it, you can safely ignore this email — nothing happens until you open the link.

This invitation was sent to {{ $email }}.
{{ config('app.name') }}
