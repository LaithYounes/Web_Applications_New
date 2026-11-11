<?php

namespace App\Modules\Orders\Models;

use Illuminate\Database\Eloquent\Model;

class PendingCheckout extends Model
{
    protected $primaryKey = 'reservation_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['reservation_id', 'cart_id', 'order_items', 'total_amount'];

    protected function casts(): array
    {
        return ['order_items' => 'array', 'total_amount' => 'decimal:2'];
    }
}
