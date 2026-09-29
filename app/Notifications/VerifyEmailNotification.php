<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * The email that confirms a new account's address (owner's rule, 2026-09-29): a signed link naming the account and
 * the address it proves, lasting auth.verification.expire minutes. It opens nothing by itself — the route asks
 * for the account to be signed in, as an invitation's link does.
 */
class VerifyEmailNotification extends Notification
{
    use Queueable;

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = self::minutes();
        $email = $notifiable->getEmailForVerification();

        // The app's own template rather than Laravel's stock one, with a plain-text twin beside it (see the
        // invitation and password-reset emails).
        return (new MailMessage)
            ->subject('Confirm your email for '.config('app.name'))
            ->view(['emails.verify-email', 'emails.verify-email-text'], [
                'url' => self::url('verification.verify', $notifiable->getKey(), $email),
                'email' => $email,
                'minutes' => $minutes,
                'days' => User::UNVERIFIED_DAYS,
            ]);
    }

    /** How long a link lasts. */
    public static function minutes(): int
    {
        return (int) config('auth.verification.expire', 60);
    }

    /** A signed link to $route for this account, naming the address it proves by its hash. */
    public static function url(string $route, int|string $userId, string $email): string
    {
        return URL::temporarySignedRoute($route, now()->addMinutes(self::minutes()), [
            'id' => $userId,
            'hash' => sha1($email),
        ]);
    }
}
