<?php

namespace App\Console\Commands;

use App\Modules\Notifications\Services\RabbitMqPublisher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class PublishOrderEmails extends Command
{
    protected $signature = 'orders:publish-emails';

    protected $description = 'Publish up to 20 pending order emails to RabbitMQ';

    public function handle(RabbitMqPublisher $publisher): int
    {
        $database = DB::connection();
        $published = 0;

        for ($i = 0; $i < 20; $i++) {
            try {
                $result = $database->transaction(
                    function () use ($database, $publisher): string {
                        $entry = $database->table('order_email_outbox')
                            ->useWritePdo()
                            ->whereNull('published_at')
                            ->where(function ($query) {
                                $query->whereNull('next_publish_at')
                                    ->orWhere('next_publish_at', '<=', now());
                            })
                            ->orderBy('id')
                            ->lockForUpdate()
                            ->first();

                        if ($entry === null) {
                            return 'empty';
                        }

                        $attempts = (int) $entry->publish_attempts + 1;

                        try {
                            $publisher->publish(
                                queue: (string) config('rabbitmq.email_queue'),
                                payload: [
                                    'schema_version' => 1,
                                    'type' => 'order.created',
                                    'message_id' => $entry->message_id,
                                    'event_id' => (int) $entry->event_id,
                                    'order_id' => (int) $entry->order_id,
                                    'email' => $entry->recipient_email,
                                ],
                                messageId: $entry->message_id,
                            );
                        } catch (Throwable $exception) {
                            $database->table('order_email_outbox')
                                ->where('id', $entry->id)
                                ->update([
                                    'publish_attempts' => $attempts,
                                    'next_publish_at' => now()->addSeconds(30),
                                    'last_publish_error' => mb_substr(
                                        $exception->getMessage(),
                                        0,
                                        2000,
                                    ),
                                    'updated_at' => now(),
                                ]);

                            $this->warn(
                                "Publishing deferred for order #{$entry->order_id}.",
                            );

                            return 'failed';
                        }

                        $database->table('order_email_outbox')
                            ->where('id', $entry->id)
                            ->update([
                                'publish_attempts' => $attempts,
                                'published_at' => now(),
                                'next_publish_at' => null,
                                'last_publish_error' => null,
                                'updated_at' => now(),
                            ]);

                        return 'published';
                    },
                );
            } catch (Throwable $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }

            if ($result === 'failed') {
                return self::FAILURE;
            }

            if ($result === 'empty') {
                break;
            }

            $published++;
        }

        $this->info("Published {$published} email message(s).");

        return self::SUCCESS;
    }
}
