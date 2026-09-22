<?php

namespace App\Notifications;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The email that carries an invitation link. The plain token lives here and in the inbox
 * only — the database keeps nothing but its hash.
 */
class InvitationNotification extends Notification
{
    use Queueable;

    public function __construct(public Invitation $invitation, public string $token) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $inviter = $this->invitation->inviter?->name;
        $role = $this->invitation->role->name;
        $place = $this->invitation->isForPlatform()
            ? 'the '.config('app.name').' team'
            : $this->invitation->store->name;

        // The app's own template rather than Laravel's stock one, with a plain-text twin beside it: an email
        // with both parts is read by every client and is trusted further by the filters in between.
        return (new MailMessage)
            ->subject("You're invited to join {$place}")
            ->view(['emails.invitation', 'emails.invitation-text'], [
                'url' => route('invitations.show', $this->token),
                'place' => $place,
                'role' => $role,
                'invitedBy' => $inviter,
                'email' => $this->invitation->email,
                'expires' => $this->invitation->expires_at->toFormattedDayDateString(),
            ]);
    }
}
