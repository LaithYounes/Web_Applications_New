<?php

namespace App\Modules\Orders\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $items = $this->items->filter(fn ($item) => $item->product !== null)->values();

        return [
            'id' => $this->id,
            'checkout_pending' => (bool) $this->checkout_pending,
            'items' => $items->map(function ($item) {
                return [
                    'id' => $item->id,
                    'product' => [
                        'id' => $item->product->id,
                        'name' => $item->product->name,
                        'price' => $item->product->price,
                        'image' => $item->product->image_path,
                    ],
                    'quantity' => $item->quantity,
                    'subtotal' => number_format(
                        (float) $item->product->price * $item->quantity,
                        2,
                        '.',
                        ''
                    ),
                ];
            }),
            'total_amount' => number_format(
                $items->sum(
                    fn ($item) => (float) $item->product->price * $item->quantity
                ),
                2,
                '.',
                ''
            ),
        ];
    }
}
