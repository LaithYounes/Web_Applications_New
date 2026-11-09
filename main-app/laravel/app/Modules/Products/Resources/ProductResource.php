<?php

namespace App\Modules\Products\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'available_quantity' => $this->when(
    isset($this->available_quantity),
    $this->available_quantity
),
            'price' => $this->price,
            'image' => Storage::disk('public')->url($this->image_path),
            'category' => new CategoryResource($this->whenLoaded('category')),
            'created_at' => $this->created_at,
        ];
    }
}
