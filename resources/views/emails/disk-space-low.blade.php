{{-- The early warning the super admins get while the server's own disk has less than the warning free
     (App\Notifications\DiskSpaceLowNotification, looked at every hour by `disk:check`): uploads still work, and
     the message says how much is left, when uploads will stop and what to do before then. --}}
<x-email.layout title="The server is running low on space"
                :preheader="'Only '.$free.' free on the server. Uploads still work, and stop when '.$reserve.' is left.'">

    <h1 style="margin:0 0 16px 0; font-size:22px; line-height:30px; font-weight:700; color:#111827;">
        The server is running low on space
    </h1>

    <p style="margin:0 0 16px 0; font-size:15px; line-height:24px; color:#374151;">
        The {{ config('app.name') }} server has only <strong style="color:#111827;">{{ $free }}</strong> of free
        space left. Uploads still work for now, but they stop for every organization once only
        <strong style="color:#111827;">{{ $reserve }}</strong> is left.
    </p>

    <p style="margin:0 0 28px 0; font-size:15px; line-height:24px; color:#374151;">
        To make room before then, delete files nobody uses, or give the server a bigger disk.
    </p>

    <x-email.button :url="$url">Open {{ config('app.name') }}</x-email.button>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
           style="border-top:1px solid #e5e7eb; margin-top:28px;">
        <tr>
            <td style="padding-top:20px; font-size:13px; line-height:20px; color:#6b7280;">
                The disk is checked every hour. This warning is sent at most once every {{ $hours }} hours while less
                than {{ $warning }} is free.
            </td>
        </tr>
    </table>

    <x-slot:footer>
        This message was sent to the super admins of {{ config('app.name') }}.
    </x-slot:footer>
</x-email.layout>
