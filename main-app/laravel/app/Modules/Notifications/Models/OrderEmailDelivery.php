<?php

namespace App\Modules\Notifications\Models;

use Illuminate\Database\Eloquent\Model;

class OrderEmailDelivery extends Model
{
    protected $fillable = [
        'outbox_id',
        'message_id',
        'status',
        'attempts',
        'lease_token',
        'lease_until',
        'next_attempt_at',
        'accepted_at',
        'failed_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'lease_until' => 'datetime',
            'next_attempt_at' => 'datetime',
            'accepted_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
