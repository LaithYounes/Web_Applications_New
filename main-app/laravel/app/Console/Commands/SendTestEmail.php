<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendTestEmail extends Command
{
    protected $signature = 'mail:send-test';

    protected $description = 'Send a test email to the configured SMTP account';

    public function handle(): int
    {
        $recipient = (string) config('mail.mailers.smtp.username');

        if (! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $this->error('MAIL_USERNAME must contain your Gmail address.');

            return self::FAILURE;
        }

        try {
            Mail::mailer('smtp')->raw(
                'This is a test email from your Laravel ecommerce project.',
                static function (Message $message) use ($recipient): void {
                    $message->to($recipient)
                        ->subject('Ecommerce SMTP test');
                },
            );

            $this->info(
                'Test email accepted by SMTP. Check your Inbox and Spam folders.',
            );

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Test email failed.');
            $this->line($exception->getMessage());

            return self::FAILURE;
        }
    }
}
