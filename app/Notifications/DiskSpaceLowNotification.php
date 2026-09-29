<?php

namespace App\Notifications;

use App\Services\DiskGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The early warning a super admin gets while the server's own disk has less than the warning free — uploads still
 * working until the reserve (App\Services\DiskGuard::warnWhenLow, looked at every hour by `disk:check`) — sent at
 * most once in DiskGuard::WARNING_HOURS hours.
 */
class DiskSpaceLowNotification extends Notification
{
    use Queueable;

    public function __construct(public int $freeBytes, public int $warningBytes, public int $reserveBytes) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('The '.config('app.name').' server has only '.DiskGuard::inWords($this->freeBytes).' free')
            ->view(['emails.disk-space-low', 'emails.disk-space-low-text'], [
                'free' => DiskGuard::inWords($this->freeBytes),
                'warning' => DiskGuard::inWords($this->warningBytes),
                'reserve' => DiskGuard::inWords($this->reserveBytes),
                'hours' => DiskGuard::WARNING_HOURS,
                'url' => route('dashboard'),
            ]);
    }
}
