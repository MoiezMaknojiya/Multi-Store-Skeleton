<?php

namespace App\Notifications;

use App\Services\DiskGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The warning a super admin gets when the server's own disk runs low and every upload is refused
 * (App\Services\DiskGuard) — sent at most once every DiskGuard::ALERT_HOURS hours.
 */
class DiskAlmostFullNotification extends Notification
{
    use Queueable;

    public function __construct(public int $freeBytes, public int $reserveBytes) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Uploads are paused: the '.config('app.name').' server is almost full')
            ->view(['emails.disk-almost-full', 'emails.disk-almost-full-text'], [
                'free' => DiskGuard::inWords($this->freeBytes),
                'reserve' => DiskGuard::inWords($this->reserveBytes),
                'hours' => DiskGuard::ALERT_HOURS,
                'url' => route('dashboard'),
            ]);
    }
}
