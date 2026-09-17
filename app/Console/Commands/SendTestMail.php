<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Prove the mail settings actually work, before a customer finds out they don't.
 *
 * Invitations and password resets both depend on this, and they fail in the most unhelpful way possible:
 * the app says the email went out, the log says nothing is wrong, and the person simply never receives
 * anything. This command turns that silence into a yes or a no.
 */
class SendTestMail extends Command
{
    protected $signature = 'mail:test {email : The address to send the test message to}';

    protected $description = 'Send a real test email to prove the MAIL_* settings in .env work';

    public function handle(): int
    {
        $recipient = (string) $this->argument('email');

        if (! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $this->error("[{$recipient}] is not an email address.");

            return self::FAILURE;
        }

        $mailer = (string) config('mail.default');
        $from = (string) config('mail.from.address');

        // Never print the password — only what is needed to see the shape of the
        // configuration.
        $this->newLine();
        $this->line('  Sending with these settings:');
        $this->table([], [
            ['mailer', $mailer],
            ['host', (string) (config("mail.mailers.{$mailer}.host") ?: '—')],
            ['port', (string) (config("mail.mailers.{$mailer}.port") ?: '—')],
            ['from', $from.' ('.config('mail.from.name').')'],
        ]);

        // Two settings that look fine and silently deliver nothing. Both are worth
        // saying out loud rather than letting the "sent!" message mislead.
        if (in_array($mailer, ['log', 'array'], true)) {
            $this->warn("  MAIL_MAILER is [{$mailer}], so nothing will leave this machine.");
            $this->line('  The message is written to storage/logs/laravel.log instead.');
            $this->line('  Set MAIL_MAILER=smtp (and the MAIL_HOST/PORT/USERNAME/PASSWORD');
            $this->line('  your provider gave you) for a real delivery.');
            $this->newLine();
        }

        if ($from === 'hello@example.com' || $from === '') {
            $this->warn('  MAIL_FROM_ADDRESS is still the placeholder.');
            $this->line('  Most providers reject a From address you do not own — set it to');
            $this->line('  an address on your own domain, or delivery will fail here.');
            $this->newLine();
        }

        try {
            Mail::raw(
                "This is a test from your signage panel.\n\n"
                ."If you are reading this in your inbox, password reset emails will\n"
                ."reach your customers too.\n\n"
                .'Sent at '.now()->toDateTimeString(),
                fn ($message) => $message->to($recipient)->subject('Mail settings test')
            );
        } catch (Throwable $e) {
            $this->newLine();
            $this->error('  The mail server refused it:');
            $this->line('  '.$e->getMessage());
            $this->newLine();

            return self::FAILURE;
        }

        if (in_array($mailer, ['log', 'array'], true)) {
            $this->info("  Written to the log. Nothing was delivered to {$recipient}.");

            return self::SUCCESS;
        }

        $this->info("  Handed to the mail server for {$recipient}.");
        $this->line('  Check that inbox — and the spam folder. If it never arrives,');
        $this->line('  the settings are accepted but the provider is dropping it,');
        $this->line('  which is almost always the From address or a missing SPF record.');
        $this->newLine();

        return self::SUCCESS;
    }
}
