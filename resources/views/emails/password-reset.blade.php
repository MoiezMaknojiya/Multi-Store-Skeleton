{{-- The password reset email (App\Notifications\ResetPasswordNotification). Nobody but the person holding
     this inbox can change the password, so the message says exactly that, and says what to do when it
     arrives unasked. --}}
<x-email.layout title="Reset your password"
                :preheader="'Use the link inside to set a new password. It expires in '.$minutes.' minutes.'">

    <h1 style="margin:0 0 16px 0; font-size:22px; line-height:30px; font-weight:700; color:#111827;">
        Reset your password
    </h1>

    <p style="margin:0 0 16px 0; font-size:15px; line-height:24px; color:#374151;">
        @if ($name)
            Hello <strong style="color:#111827;">{{ $name }}</strong> — we received a request to reset the
            password for <strong style="color:#111827;">{{ $email }}</strong>.
        @else
            We received a request to reset the password for <strong style="color:#111827;">{{ $email }}</strong>.
        @endif
    </p>

    <p style="margin:0 0 28px 0; font-size:15px; line-height:24px; color:#374151;">
        Open the link below to choose a new one.
    </p>

    <x-email.button :url="$url">Reset password</x-email.button>

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
                This link expires in <strong style="color:#374151;">{{ $minutes }} minutes</strong> and can only
                be used once. If you didn't ask for it, nothing has changed — your password stays as it is and
                you can ignore this email.
            </td>
        </tr>
    </table>

    <x-slot:footer>
        This message was sent to {{ $email }} because a password reset was requested for that account.
    </x-slot:footer>
</x-email.layout>
