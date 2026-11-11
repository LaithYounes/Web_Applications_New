<?php

namespace App\Modules\Notifications\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationDelivery extends Model
{
    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['lease_until' => 'immutable_datetime', 'next_attempt_at' => 'immutable_datetime', 'attempts' => 'integer'];
    }
}
