<?php

namespace App\Modules\Notifications\Models;

use Illuminate\Database\Eloquent\Model;

class OrderNotification extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime', 'read_at' => 'immutable_datetime'];
    }

    public function payload(): array
    {
        return [
            'notification_id' => $this->notification_id,
            'type' => $this->type,
            'order_id' => (string) $this->order_id,
            'order_status' => $this->order_status,
            'title' => $this->title,
            'body' => $this->body,
        ];
    }
}
