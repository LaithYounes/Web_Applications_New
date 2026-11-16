<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

class SetupRabbitMq extends Command
{
    protected $signature = 'rabbitmq:setup';

    protected $description = 'Create the order email queues and retry policies';

    public function handle(): int
    {
        try {
            $connection = new AMQPStreamConnection(
                host: (string) config('rabbitmq.host'),
                port: (int) config('rabbitmq.port'),
                user: (string) config('rabbitmq.user'),
                password: (string) config('rabbitmq.password'),
                vhost: (string) config('rabbitmq.vhost'),
                connection_timeout: 5.0,
                read_write_timeout: 10.0,
                channel_rpc_timeout: 5.0,
            );

            try {
                $channel = $connection->channel();

                $queues = [
                    config('rabbitmq.email_queue'),
                    config('rabbitmq.email_retry_queue'),
                    config('rabbitmq.email_dlq'),
                ];

                foreach ($queues as $queue) {
                    $channel->queue_declare(
                        queue: $queue,
                        passive: false,
                        durable: true,
                        exclusive: false,
                        auto_delete: false,
                        nowait: false,
                        arguments: new AMQPTable([
                            'x-queue-type' => 'quorum',
                        ]),
                    );

                    $this->line("Queue ready: {$queue}");
                }

                $channel->close();
            } finally {
                $connection->close();
            }

            $this->applyPolicy(
                name: 'order-email-main',
                queue: (string) config('rabbitmq.email_queue'),
                definition: [
                    'dead-letter-exchange' => '',
                    'dead-letter-routing-key' => config('rabbitmq.email_dlq'),
                    'dead-letter-strategy' => 'at-least-once',
                    'overflow' => 'reject-publish',
                ],
            );

            $this->applyPolicy(
                name: 'order-email-retry',
                queue: (string) config('rabbitmq.email_retry_queue'),
                definition: [
                    'message-ttl' => (int) config(
                        'rabbitmq.email_retry_delay_seconds',
                    ) * 1000,
                    'dead-letter-exchange' => '',
                    'dead-letter-routing-key' => config('rabbitmq.email_queue'),
                    'dead-letter-strategy' => 'at-least-once',
                    'overflow' => 'reject-publish',
                ],
            );

            $this->info('RabbitMQ queues and policies are ready.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('RabbitMQ setup failed.');
            $this->line($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function applyPolicy(
        string $name,
        string $queue,
        array $definition,
    ): void {
        $url = sprintf(
            'http://%s:%d/api/policies/%s/%s',
            (string) config('rabbitmq.host'),
            (int) config('rabbitmq.management_port'),
            rawurlencode((string) config('rabbitmq.vhost')),
            rawurlencode($name),
        );

        Http::withBasicAuth(
            (string) config('rabbitmq.user'),
            (string) config('rabbitmq.password'),
        )
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(10)
            ->put($url, [
                'pattern' => '^'.preg_quote($queue, '/').'$',
                'definition' => $definition,
                'priority' => 10,
                'apply-to' => 'queues',
            ])
            ->throw();

        $this->line("Policy ready: {$name}");
    }
}
