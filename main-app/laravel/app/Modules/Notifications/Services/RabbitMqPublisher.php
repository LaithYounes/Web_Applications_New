<?php

namespace App\Modules\Notifications\Services;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use RuntimeException;

class RabbitMqPublisher
{
    public function publish(string $queue,array $payload,string $messageId,): void {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

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
            $channel->confirm_select();

            $confirmed = false;
            $returned = false;

            $channel->set_ack_handler(
                static function () use (&$confirmed): void {
                    $confirmed = true;
                },
            );

            $channel->set_nack_handler(
                static function (): void {
                    throw new RuntimeException(
                        'RabbitMQ rejected the published message.',
                    );
                },
            );

            $channel->set_return_listener( static function () use (&$returned): void {
                    $returned = true;
                },
            );

            $message = new AMQPMessage($body, [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'message_id' => $messageId,
            ]);

            $channel->basic_publish(
                msg: $message,
                exchange: '',
                routing_key: $queue,
                mandatory: true,
            );

            $channel->wait_for_pending_acks_returns(5.0);

            if ($returned) {
                throw new RuntimeException(
                    'The message could not be routed to the requested queue.',
                );
            }

            if (! $confirmed) {
                throw new RuntimeException(
                    'RabbitMQ did not confirm the message.',
                );
            }

            $channel->close();
        } finally {
            $connection->close();
        }
    }
}
