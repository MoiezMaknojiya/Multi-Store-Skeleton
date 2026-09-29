{{-- The email that confirms a new account's address (App\Notifications\VerifyEmailNotification), sent when a
     customer signs up. Until it is opened the account can do nothing that uses the server's space, and an
     account never confirmed is removed after $days days (owner's rule, 2026-09-29) — so the message says both.
     It carries no text anybody typed but the address itself — no name: it can be sent to any address a visitor
     types, and a name is where signup spam puts its words. --}}
<x-email.layout title="Confirm your email"
                :preheader="'One click and your '.config('app.name').' account is ready. The link works for '.$minutes.' minutes.'">

    <h1 style="margin:0 0 16px 0; font-size:22px; line-height:30px; font-weight:700; color:#111827;">
        Confirm your email
    </h1>

    <p style="margin:0 0 16px 0; font-size:15px; line-height:24px; color:#374151;">
        Thank you for signing up to {{ config('app.name') }}. Open the link below to confirm that
        <strong style="color:#111827;">{{ $email }}</strong> is your address.
    </p>

    <p style="margin:0 0 28px 0; font-size:15px; line-height:24px; color:#374151;">
        Once it is confirmed you can add your screens, upload your pictures and videos, and build your ads.
    </p>

    <x-email.button :url="$url">Confirm my email</x-email.button>

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
                expired, sign in and ask for a new one. An account that is not confirmed within
                <strong style="color:#374151;">{{ $days }} days</strong> is removed, with its store. If you didn't
                sign up, ignore this email: nothing more will happen.
            </td>
        </tr>
    </table>

    <x-slot:footer>
        This message was sent to {{ $email }} because an account was created with that address.
    </x-slot:footer>
</x-email.layout>
