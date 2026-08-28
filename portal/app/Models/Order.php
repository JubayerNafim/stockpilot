<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'organization_id',
    'store_id',
    'woocommerce_id',
    'woocommerce_number',
    'status',
    'reservation_state',
    'customer_name',
    'customer_email',
    'currency',
    'total',
    'subtotal',
    'discount_total',
    'shipping_total',
    'tax_total',
    'refund_total',
    'return_charge',
    'order_created_at',
    'order_updated_at',
    'payload',
    'sync_status',
    'sync_hash',
    'reserved_at',
    'confirmed_at',
    'released_at',
    'returned_at',
    'last_webhook_event_id',
])]
class Order extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'sp_orders';

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
        'reservation_state' => 'none',
        'sync_status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'total' => 'float',
            'subtotal' => 'float',
            'discount_total' => 'float',
            'shipping_total' => 'float',
            'tax_total' => 'float',
            'refund_total' => 'float',
            'return_charge' => 'float',
            'order_created_at' => 'datetime',
            'order_updated_at' => 'datetime',
            'payload' => 'array',
            'reserved_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'released_at' => 'datetime',
            'returned_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(StoreConnection::class, 'store_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function components(): HasMany
    {
        return $this->hasMany(OrderItemComponent::class);
    }

    public function statusEvents(): HasMany
    {
        return $this->hasMany(OrderStatusEvent::class);
    }
}
