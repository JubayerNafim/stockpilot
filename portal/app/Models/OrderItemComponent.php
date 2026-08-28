<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id',
    'order_id',
    'order_item_id',
    'product_id',
    'item_id',
    'component_product_id',
    'quantity_per_unit',
    'order_quantity',
    'total_quantity',
    'reserved_quantity',
    'confirmed_quantity',
    'returned_quantity',
    'status',
    'charge_amount',
    'reserved_at',
    'confirmed_at',
    'released_at',
    'returned_at',
])]
class OrderItemComponent extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'sp_order_item_components';

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'quantity_per_unit' => 'float',
            'order_quantity' => 'float',
            'total_quantity' => 'float',
            'reserved_quantity' => 'float',
            'confirmed_quantity' => 'float',
            'returned_quantity' => 'float',
            'charge_amount' => 'float',
            'reserved_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'released_at' => 'datetime',
            'returned_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Raw-material component (item_id). */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** Product component (component_product_id) — a sub-product consumed by the sellable product. */
    public function componentProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'component_product_id');
    }
}
