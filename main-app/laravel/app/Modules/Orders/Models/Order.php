<?php

namespace App\Modules\Orders\Models;

use App\Models\User;
use App\Modules\Notifications\Services\OrderNotificationRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Modules\Orders\Events\OrderCreated;
use App\Modules\Orders\Events\OrderStatusUpdated;
class Order extends Model
{
    public const CANCELLING = 'cancelling';

    protected $attributes = ['status' => 'pending'];

    private ?User $statusActor = null;

    protected $fillable = [
        'user_id',
        'status',
        'total_amount',
        'reservation_id',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::created(fn (Order $order) => $order->recordStatusChange(null));
        static::updated(function (Order $order): void {
            if ($order->wasChanged('status')) {
                $order->recordStatusChange($order->getRawOriginal('status'));
            }
        });
    }

    public function withStatusActor(?User $actor): static
    {
        $this->statusActor = $actor;

        return $this;
    }

    public function save(array $options = []): bool
    {
        try {
            // The synchronous model events write history on this same connection.
            // Even a direct Eloquent save must not persist a state without its audit.
            return $this->getConnection()->transaction(fn () => parent::save($options));
        } finally {
            $this->statusActor = null;
        }
    }

        private function recordStatusChange(?string $previous): void
    {
        $event = $this->statusHistory()->create([
            'from_status' => $previous,
            'to_status' => $this->status,
            'changed_by' => $this->statusActor?->id,
            'actor_type' => $this->statusActor?->role ?? 'system',
            'changed_at' => now(),
        ]);

        app(OrderNotificationRecorder::class)->record($this, $event);

        if ($previous === null && $event->to_status === 'pending') {
            $broadcastEvent = new OrderCreated(
                orderId: (int) $this->getKey(),
                eventId: (int) $event->getKey(),
            );
        } elseif ($previous !== null) {
            $broadcastEvent = new OrderStatusUpdated(
                orderId: (int) $this->getKey(),
                eventId: (int) $event->getKey(),
                status: $event->to_status,
            );
        } else {
            return;
        }

        $this->getConnection()->afterCommit(
            static fn () => event($broadcastEvent)
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('id');
    }
}
