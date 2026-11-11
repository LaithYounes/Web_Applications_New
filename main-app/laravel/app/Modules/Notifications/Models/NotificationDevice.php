<?php

namespace App\Modules\Notifications\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationDevice extends Model
{
    protected $guarded = [];

    protected $hidden = ['token', 'token_hash', 'session_id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['token' => 'encrypted', 'active' => 'boolean', 'revision' => 'integer'];
    }
}
