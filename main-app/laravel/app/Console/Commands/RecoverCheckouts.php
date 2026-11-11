<?php

namespace App\Console\Commands;

use App\Modules\Inventory\Exceptions\InventoryRejectedException;
use App\Modules\Orders\Models\PendingCheckout;
use App\Modules\Orders\Services\OrderService;
use Illuminate\Console\Command;
use Throwable;

class RecoverCheckouts extends Command
{
    protected $signature = 'orders:recover-checkouts {--min-age=60 : Minimum age in seconds} {--limit=100 : Maximum operations per run}';

    protected $description = 'Resume durable checkouts with an unconfirmed inventory result';

    public function handle(OrderService $orders): int
    {
        $failed = false;
        $checkouts = PendingCheckout::where('updated_at', '<=', now()->subSeconds(max(0, (int) $this->option('min-age'))))
            ->orderBy('updated_at')->limit(max(1, (int) $this->option('limit')))->pluck('reservation_id');

        foreach ($checkouts as $reservationId) {
            try {
                $order = $orders->finishCheckout($reservationId);
                $this->info("Checkout {$reservationId}: order {$order->id} saved.");
            } catch (InventoryRejectedException $exception) {
                $this->warn("Checkout {$reservationId}: {$exception->getMessage()}");
            } catch (Throwable $exception) {
                report($exception);
                // Back off and move failures behind other work instead of letting
                // the oldest unavailable operation monopolize every bounded batch.
                PendingCheckout::whereKey($reservationId)->update(['updated_at' => now()]);
                $this->error("Checkout {$reservationId}: recovery deferred; see application logs.");
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
