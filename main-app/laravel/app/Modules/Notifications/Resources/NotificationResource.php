<?php

namespace App\Modules\Notifications\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->notification_id, 'type' => $this->type,
            'title' => $this->title, 'body' => $this->body,
            'order_id' => $this->order_id, 'order_status' => $this->order_status,
            'created_at' => $this->created_at->utc()->toISOString(),
            'read_at' => $this->read_at?->utc()->toISOString(),
        ];
    }
}
