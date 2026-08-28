<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'order_id',
    'status_from',
    'status_to',
    'webhook_event_id',
    'payload',
])]
class OrderStatusEvent extends \Illuminate\Database\Eloquent\Model
{
    public const CREATED_AT = 'occurred_at';

    public const UPDATED_AT = null;

    protected $table = 'sp_order_status_events';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
