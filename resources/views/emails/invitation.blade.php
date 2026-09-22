{{-- The invitation email (App\Notifications\InvitationNotification). The link carries the only copy of the
     plain token there is — the database keeps its hash — so the wording makes plain who sent it, where it
     leads and when it stops working. --}}
<x-email.layout :title="'You are invited to join '.$place"
                :preheader="$invitedBy ? $invitedBy.' invited you to join '.$place.' as '.$role.'.' : 'You have been invited to join '.$place.' as '.$role.'.'">

    <h1 style="margin:0 0 16px 0; font-size:22px; line-height:30px; font-weight:700; color:#111827;">
        You're invited to join {{ $place }}
    </h1>

    <p style="margin:0 0 16px 0; font-size:15px; line-height:24px; color:#374151;">
        @if ($invitedBy)
            <strong style="color:#111827;">{{ $invitedBy }}</strong> has invited you to join
            <strong style="color:#111827;">{{ $place }}</strong> as <strong style="color:#111827;">{{ $role }}</strong>.
        @else
            You have been invited to join <strong style="color:#111827;">{{ $place }}</strong> as
            <strong style="color:#111827;">{{ $role }}</strong>.
        @endif
    </p>

    <p style="margin:0 0 28px 0; font-size:15px; line-height:24px; color:#374151;">
        Open the link below to accept. If you don't have an account yet, you'll set your password on the same
        page — nobody else ever sets it for you.
    </p>

    <x-email.button :url="$url">Accept invitation</x-email.button>

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
                This invitation expires on <strong style="color:#374151;">{{ $expires }}</strong>, and the link
                can only be used once. If you weren't expecting it, you can safely ignore this email — nothing
                happens until you open the link.
            </td>
        </tr>
    </table>

    <x-slot:footer>
        This invitation was sent to {{ $email }}.
    </x-slot:footer>
</x-email.layout>
