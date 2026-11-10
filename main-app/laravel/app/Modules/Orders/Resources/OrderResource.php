<?php

namespace App\Modules\Orders\Resources;

use App\Modules\Orders\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $cancellationPending = $this->status === Order::CANCELLING;

        return [
            'id' => $this->id,
            'status' => $cancellationPending ? 'pending' : $this->status,
            'cancellation_pending' => $this->when($cancellationPending, true),
            'total_amount' => $this->total_amount,
            'items' => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_name' => $item->product_name,
                'unit_price' => $item->unit_price,
                'quantity' => $item->quantity,
                'subtotal' => $item->subtotal,
            ]),
            'created_at' => $this->created_at,
        ];
    }
}
