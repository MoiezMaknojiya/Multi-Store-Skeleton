<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The "forgot password" email, in the app's own template rather than Laravel's stock one (User::
 * sendPasswordResetNotification hands it over). The link carries the only copy of the plain token; the
 * database keeps a hash of it, and it stops working after `auth.passwords.users.expire` minutes.
 *
 * Nobody but the holder of the inbox ever sets a password in this app — no admin does it for them — so the
 * message says where the request came from and what to do if it was not theirs.
 */
class ResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(public string $token) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $email = $notifiable->getEmailForPasswordReset();

        return (new MailMessage)
            ->subject('Reset your '.config('app.name').' password')
            ->view(['emails.password-reset', 'emails.password-reset-text'], [
                'url' => route('password.reset', ['token' => $this->token, 'email' => $email]),
                'name' => $notifiable->name ?? null,
                'email' => $email,
                'minutes' => (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60),
            ]);
    }
}
