{{-- The first step of a confirmed account's change of email (App\Notifications\ApproveEmailChangeNotification; owner,
     2026-10-15): sent to the address the account has NOW, asking its person to approve the new one before the new address
     gets its own link — so an open session alone cannot move the account to somebody else's inbox. Like every email that
     confirms an address it carries no text anybody typed but the recipient's own address: the new one is masked. --}}
<x-email.layout title="Approve your new sign-in email"
                :preheader="'Nothing changes until you approve it. The link works for '.$minutes.' minutes.'">

    <h1 style="margin:0 0 16px 0; font-size:22px; line-height:30px; font-weight:700; color:#111827;">
        Approve your new sign-in email
    </h1>

    <p style="margin:0 0 16px 0; font-size:15px; line-height:24px; color:#374151;">
        Someone asked to change the sign-in email of your {{ config('app.name') }} account to
        <strong style="color:#111827;">{{ $newEmail }}</strong>.
    </p>

    <p style="margin:0 0 28px 0; font-size:15px; line-height:24px; color:#374151;">
        If it was you, approve it below. We then send a link to the new address, and the change is made once it is
        opened. Until then you keep signing in with <strong style="color:#111827;">{{ $email }}</strong>.
    </p>

    <x-email.button :url="$url">Approve the Change</x-email.button>

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
                nothing changes. Then sign in and change your password, since someone may have your account open.
            </td>
        </tr>
    </table>

    <x-slot:footer>
        This message was sent to {{ $email }} because someone asked to change the sign-in email of the account that uses it.
    </x-slot:footer>
</x-email.layout>
