<?php

namespace App\Console\Commands;

use App\Modules\Notifications\Services\OrderEmailProcessor;
use App\Modules\Notifications\Services\RabbitMqPublisher;
use Illuminate\Console\Command;
use InvalidArgumentException;
use JsonException;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use RuntimeException;
use Throwable;

class ConsumeOrderEmails extends Command
{
    protected $signature = 'orders:consume-emails';

    protected $description = 'Consume order emails with manual acknowledgements';

    public function handle(
        OrderEmailProcessor $processor,
        RabbitMqPublisher $publisher,
    ): int {
        pcntl_async_signals(true);

        pcntl_signal(SIGALRM, static function (): void {
            fwrite(STDERR, "Email processing exceeded 120 seconds. Worker exiting.\n");
            exit(1);
        });

        $stop = false;

        $this->trap([SIGINT, SIGTERM], static function () use (&$stop): void {
            $stop = true;
        });

        try {
            $connection = new AMQPStreamConnection(
                host: (string) config('rabbitmq.host'),
                port: (int) config('rabbitmq.port'),
                user: (string) config('rabbitmq.user'),
                password: (string) config('rabbitmq.password'),
                vhost: (string) config('rabbitmq.vhost'),
                connection_timeout: 5.0,
                read_write_timeout: 130.0,
                heartbeat: 60,
                channel_rpc_timeout: 5.0,
            );

            try {
                $channel = $connection->channel();
                $channel->basic_qos(0, 1, false);

                $channel->basic_consume(
                    queue: (string) config('rabbitmq.email_queue'),
                    consumer_tag: '',
                    no_local: false,
                    no_ack: false,
                    exclusive: false,
                    nowait: false,
                    callback: function (AMQPMessage $message) use (
                        $processor,
                        $publisher,
                    ): void {
                        pcntl_alarm(120);

                        try {
                            try {
                                $payload = json_decode(
                                    $message->getBody(),
                                    true,
                                    32,
                                    JSON_THROW_ON_ERROR,
                                );

                                if (! is_array($payload)) {
                                    throw new InvalidArgumentException('Expected a JSON object.');
                                }

                                $messageId = $message->has('message_id')
                                    ? (string) $message->get('message_id')
                                    : '';

                                $action = $processor->process($payload, $messageId);
                            } catch (JsonException|InvalidArgumentException $exception) {
                                $this->warn('Invalid message rejected: '.$exception->getMessage());
                                $message->reject(false);

                                return;
                            }

                            if ($action === 'retry') {
                                $publisher->publish(
                                    queue: (string) config('rabbitmq.email_retry_queue'),
                                    payload: $payload,
                                    messageId: $messageId,
                                );

                                $message->ack();
                                $this->warn("Retry scheduled for order #{$payload['order_id']}.");
                            } elseif ($action === 'reject') {
                                $message->reject(false);
                                $this->warn("DLQ routing requested for order #{$payload['order_id']}.");
                            } elseif ($action === 'ack') {
                                $message->ack();
                                $this->info("Email task acknowledged for order #{$payload['order_id']}.");
                            } else {
                                throw new RuntimeException('Unknown email processing action.');
                            }
                        } catch (Throwable $exception) {
                            // Do not confuse a publishing timeout with an idle consumer timeout.
                            throw new RuntimeException(
                                'Email task interrupted: '.$exception->getMessage(),
                                0,
                                $exception,
                            );
                        } finally {
                            pcntl_alarm(0);
                        }
                    },
                );

                $this->info('Waiting for order emails. Press Ctrl+C to stop.');

                while (! $stop && $channel->is_consuming()) {
                    try {
                        $channel->wait(null, false, 1);
                    } catch (AMQPTimeoutException) {
                        // No message arrived during this one-second wait.
                    }
                }

                $channel->close();
            } finally {
                $connection->close();
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Email worker stopped: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
