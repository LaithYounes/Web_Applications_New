<?php

namespace App\Console\Commands;

use App\Modules\Notifications\Jobs\SendOrderPush;
use App\Modules\Notifications\Models\NotificationDelivery;
use Illuminate\Console\Command;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Facades\DB;

class RetryNotificationDelivery extends Command
{
    protected $signature = 'notifications:retry-delivery {id : Failed delivery ID}';

    protected $description = 'Explicitly requeue one failed push delivery after fixing its cause';

    public function handle(): int
    {
        return DB::transaction(function () {
            $delivery = NotificationDelivery::whereKey($this->argument('id'))->lockForUpdate()->first();
            if (! $delivery || $delivery->status !== 'failed' || $delivery->driver !== config('order_notifications.driver')) {
                $this->error('Only a failed delivery for the current driver can be retried; skipped and accepted rows are never replayed.');

                return self::FAILURE;
            }
            $delivery->update(['status' => 'pending', 'attempts' => 0, 'error_code' => null, 'next_attempt_at' => null, 'lease_until' => null]);
            $queue = new DatabaseQueue(DB::connection(), 'jobs', 'notifications', 180, false);
            $queue->setContainer(app());
            $queue->push(new SendOrderPush($delivery->id), '', 'notifications');
            $this->info('Delivery requeued. Device ownership and revision will be checked again by the worker.');

            return self::SUCCESS;
        });
    }
}
