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

        $invitedLine = $inviter
            ? "{$inviter} has invited you to join {$place} as {$role}."
            : "You have been invited to join {$place} as {$role}.";

        return (new MailMessage)
            ->subject("You're invited to join {$place}")
            ->greeting('Hello!')
            ->line($invitedLine)
            ->action('Accept invitation', route('invitations.show', $this->token))
            ->line('This invitation expires on '.$this->invitation->expires_at->toFormattedDayDateString().'.')
            ->line("If you weren't expecting it, you can safely ignore this email.");
    }
}
