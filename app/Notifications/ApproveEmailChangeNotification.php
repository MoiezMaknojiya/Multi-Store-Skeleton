<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * The first step of a confirmed account's change of email (owner, 2026-10-15 — "email badalne per email verification ho jese
 * sub karte ha", the way Discord and X do it): sent to the address the account has NOW, asking its person to approve the new
 * one. Only once this link is opened does ConfirmNewEmailNotification go to the new address — so somebody at an open session
 * (a shared computer, a stolen cookie, "Log In As") cannot move the account to an inbox of their own and then reset its
 * password from there.
 *
 * Like every email that confirms an address it carries no text anybody typed but the recipient's own address: the new one is
 * shown masked (its first character and its domain), enough for its person to know it and no help to a stranger.
 */
class ApproveEmailChangeNotification extends Notification
{
    use Queueable;

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Approve a new sign-in email for '.config('app.name'))
            ->view(['emails.approve-email-change', 'emails.approve-email-change-text'], [
                'url' => self::url($notifiable),
                'email' => $notifiable->email,
                'newEmail' => self::masked((string) $notifiable->pending_email),
                'minutes' => VerifyEmailNotification::minutes(),
            ]);
    }

    /**
     * The signed link that approves this change, lasting as long as every other email link: it names the account, and the
     * address it has and the address asked for together, so a link for an earlier change (or sent before the account's own
     * address changed) approves nothing.
     */
    public static function url(User $user): string
    {
        return URL::temporarySignedRoute('email.approve', now()->addMinutes(VerifyEmailNotification::minutes()), [
            'id' => $user->getKey(),
            'hash' => self::hashOf((string) $user->email, (string) $user->pending_email),
        ]);
    }

    /** The two addresses a change is between, as one hash: each hashed first, so no address can run into the other. */
    public static function hashOf(string $current, string $new): string
    {
        return sha1(sha1($current).sha1($new));
    }

    /** "b•••@example.com": an address its own person knows at a glance, with nothing of it a stranger could use. */
    public static function masked(string $email): string
    {
        $at = strrpos($email, '@');

        if ($at === false || $at === 0) {
            return '•••';
        }

        return mb_substr($email, 0, 1).'•••'.substr($email, $at);
    }
}
