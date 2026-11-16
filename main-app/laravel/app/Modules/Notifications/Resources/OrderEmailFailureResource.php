<?php

namespace App\Modules\Notifications\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderEmailFailureResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'message_id' => $this->message_id,
            'order_id' => (int) $this->order_id,
            'recipient_email' => $this->recipient_email,
            'status' => $this->status,
            'attempts' => (int) $this->attempts,

            'failure_message' => 'Email processing failed. Technical review is required.',

            'failed_at' => $this->failed_at?->utc()->toISOString(),
            'created_at' => $this->created_at?->utc()->toISOString(),
            'updated_at' => $this->updated_at?->utc()->toISOString(),
        ];
    }
}
