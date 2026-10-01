{{-- The email that confirms a CHANGED address (App\Notifications\ConfirmNewEmailNotification), sent to the new
     address from the Profile. Until it is opened the account keeps its old address, so a typo can never lock
     anybody out — and the message says that the change waits for this click. Like the signup's, it carries no
     text anybody typed but the address itself: it goes to whatever address was typed. --}}
<x-email.layout title="Confirm your new email"
                :preheader="'Your email changes once you open this link. It works for '.$minutes.' minutes.'">

    <h1 style="margin:0 0 16px 0; font-size:22px; line-height:30px; font-weight:700; color:#111827;">
        Confirm your new email
    </h1>

    <p style="margin:0 0 16px 0; font-size:15px; line-height:24px; color:#374151;">
        Someone asked to change the email of a {{ config('app.name') }} account to
        <strong style="color:#111827;">{{ $email }}</strong>.
    </p>

    <p style="margin:0 0 28px 0; font-size:15px; line-height:24px; color:#374151;">
        If it was you, open the link below to confirm it. Until you do, the account keeps its old address.
    </p>

    <x-email.button :url="$url">Confirm New Email</x-email.button>

    <p style="margin:28px 0 8px 0; font-size:13px; line-height:20px; color:#6b7280;">
        Or paste this address into your browser:
    </p>
    <p style="margin:0 0 24px 0; font-size:13px; line-height:20px; word-break:break-all;">
        <a href="{{ $url }}" target="_blank" rel="noopener" style="color:#2563eb; text-decoration:underline;">{{ $url }}</a>
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
           style="border-top:1px solid #e5e7eb; margin-top:4px;">
        <tr>
            <td style="padding-top:20px; font-size:13px; line-height:20px; color:#6b7280;">
                This link works for <strong style="color:#374151;">{{ $minutes }} minutes</strong>. If it has
                expired, ask for a new one from your profile. If you didn't ask for this change, ignore this email:
                the account keeps its old address.
            </td>
        </tr>
    </table>

    <x-slot:footer>
        This message was sent to {{ $email }} because it was entered as a new address for an account.
    </x-slot:footer>
</x-email.layout>
