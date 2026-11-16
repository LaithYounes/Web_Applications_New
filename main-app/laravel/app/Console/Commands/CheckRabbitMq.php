<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Throwable;

class CheckRabbitMq extends Command
{
    protected $signature = 'rabbitmq:check';

    protected $description = 'Check the RabbitMQ connection and credentials';

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
            );

            $connection->close();

            $this->info('RabbitMQ connection successful.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('RabbitMQ connection failed.');
            $this->line($exception->getMessage());

            return self::FAILURE;
        }
    }
}
