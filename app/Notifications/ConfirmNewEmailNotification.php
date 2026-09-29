<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The email that confirms a changed address, sent to the NEW address from the Profile. The account keeps its old
 * address until this link is opened (users.pending_email), so a typo can never lock anybody out — the industry's
 * way of changing an email.
 */
class ConfirmNewEmailNotification extends Notification
{
    use Queueable;

    public function __construct(public User $user) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Confirm your new email for '.config('app.name'))
            ->view(['emails.confirm-new-email', 'emails.confirm-new-email-text'], [
                'url' => VerifyEmailNotification::url('email.confirm', $this->user->getKey(), (string) $this->user->pending_email),
                'email' => $this->user->pending_email,
                'minutes' => VerifyEmailNotification::minutes(),
            ]);
    }
}
