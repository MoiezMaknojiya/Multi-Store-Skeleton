{{-- The warning the super admins get when the server's own disk runs low (App\Notifications\DiskAlmostFullNotification):
     every upload is refused until there is room again (App\Services\DiskGuard), whatever each shop's own allowance
     says — so the message says what is happening, how much is left and what to do. --}}
<x-email.layout title="Uploads are paused: the server is almost full"
                :preheader="'Only '.$free.' free on the server. Uploads are refused until there is more room.'">

    <h1 style="margin:0 0 16px 0; font-size:22px; line-height:30px; font-weight:700; color:#111827;">
        Uploads are paused: the server is almost full
    </h1>

    <p style="margin:0 0 16px 0; font-size:15px; line-height:24px; color:#374151;">
        The server has only <strong style="color:#111827;">{{ $free }}</strong> of free space left, and
        {{ config('app.name') }} keeps at least <strong style="color:#111827;">{{ $reserve }}</strong> free for
        itself. Until there is more room, every upload is refused: pictures, videos, Ad Builder files and adverts.
    </p>

    <p style="margin:0 0 28px 0; font-size:15px; line-height:24px; color:#374151;">
        Screens keep playing what they already have. To make room, delete files nobody uses, or give the server a
        bigger disk.
    </p>

    <x-email.button :url="$url">Open {{ config('app.name') }}</x-email.button>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
           style="border-top:1px solid #e5e7eb; margin-top:28px;">
        <tr>
            <td style="padding-top:20px; font-size:13px; line-height:20px; color:#6b7280;">
                This warning is sent at most once every {{ $hours }} hours while the disk stays this full.
            </td>
        </tr>
    </table>

    <x-slot:footer>
        This message was sent to the super admins of {{ config('app.name') }}.
    </x-slot:footer>
</x-email.layout>
