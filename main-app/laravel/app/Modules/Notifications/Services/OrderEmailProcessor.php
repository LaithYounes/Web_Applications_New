<?php

namespace App\Modules\Notifications\Services;

use App\Modules\Notifications\Mail\OrderConfirmationMail;
use App\Modules\Notifications\Models\OrderEmailDelivery;
use App\Modules\Orders\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class OrderEmailProcessor
{
    public function process(array $payload, string $messageId): string
    {
        if (DB::transactionLevel() > 0) {
            throw new RuntimeException('Process order emails outside database transactions.');
        }

        if (
            ! Str::isUuid($messageId)
            || ($payload['message_id'] ?? null) !== $messageId
            || ($payload['schema_version'] ?? null) !== 1
            || ($payload['type'] ?? null) !== 'order.created'
            || ! is_int($payload['order_id'] ?? null)
            || ! is_int($payload['event_id'] ?? null)
        ) {
            throw new InvalidArgumentException('Invalid order email message.');
        }

        $outbox = DB::table('order_email_outbox')
            ->useWritePdo()
            ->where('message_id', $messageId)
            ->first();

        if (
            $outbox === null
            || (int) $outbox->order_id !== $payload['order_id']
            || (int) $outbox->event_id !== $payload['event_id']
            || $outbox->recipient_email !== ($payload['email'] ?? null)
        ) {
            throw new InvalidArgumentException('Message does not match its outbox record.');
        }

        $claim = $this->claim($outbox);

        if (is_string($claim)) {
            return $claim;
        }
        

        $error = null;

        try {
            $order = Order::query()->useWritePdo()->findOrFail($outbox->order_id);

            $sent = Mail::mailer('smtp')
                ->to($outbox->recipient_email)
                ->send(new OrderConfirmationMail($order));

            if ($sent === null) {
                throw new RuntimeException('Email sending was cancelled.');
            }
        } catch (Throwable $exception) {
            $error = $exception;
        }

        return $this->finish($claim, $error);
    }

    private function claim(object $outbox): OrderEmailDelivery|string
    {
        return DB::transaction(function () use ($outbox): OrderEmailDelivery|string {
            DB::table('order_email_deliveries')->insertOrIgnore([
                'outbox_id' => $outbox->id,
                'message_id' => $outbox->message_id,
                'status' => 'pending',
                'attempts' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $delivery = OrderEmailDelivery::query()
                ->useWritePdo()
                ->where('message_id', $outbox->message_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($delivery->status === 'accepted') {
                return 'ack';
            }

            if ($delivery->status === 'failed') {
                return 'reject';
            }

            if (
                $delivery->lease_until?->isFuture()
                || $delivery->next_attempt_at?->isFuture()
            ) {
                return 'retry';
            }

            if ($delivery->attempts >= config('rabbitmq.email_max_attempts')) {
                $delivery->update([
                    'status' => 'failed',
                    'failed_at' => now(),
                    'lease_token' => null,
                    'lease_until' => null,
                    'next_attempt_at' => null,
                    'last_error' => 'Attempt limit reached after interrupted processing.',
                ]);

                return 'reject';
            }

            $delivery->update([
                'status' => 'sending',
                'attempts' => $delivery->attempts + 1,
                'lease_token' => (string) Str::uuid(),
                'lease_until' => now()->addSeconds(180),
                'next_attempt_at' => null,
            ]);

            return $delivery;
        });
    }

    private function finish(OrderEmailDelivery $claim, ?Throwable $error): string
    {
        return DB::transaction(function () use ($claim, $error): string {
            $delivery = OrderEmailDelivery::query()
                ->useWritePdo()
                ->whereKey($claim->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($delivery->status === 'accepted') {
                return 'ack';
            }

            if ($delivery->status === 'failed') {
                return 'reject';
            }

            if (
                $delivery->status !== 'sending'
                || $delivery->lease_token !== $claim->lease_token
            ) {
                return 'retry';
            }

            if ($error === null) {
                $delivery->update([
                    'status' => 'accepted',
                    'accepted_at' => now(),
                    'lease_token' => null,
                    'lease_until' => null,
                    'next_attempt_at' => null,
                    'failed_at' => null,
                    'last_error' => null,
                ]);

                return 'ack';
            }

            $failed = $delivery->attempts >= config('rabbitmq.email_max_attempts');

            $delivery->update([
                'status' => $failed ? 'failed' : 'pending',
                'lease_token' => null,
                'lease_until' => null,
                'next_attempt_at' => $failed
                    ? null
                    : now()->addSeconds(config('rabbitmq.email_retry_delay_seconds')),
                'failed_at' => $failed ? now() : null,
                'last_error' => mb_substr($error->getMessage(), 0, 2000),
            ]);

            return $failed ? 'reject' : 'retry';
        });
    }
}
