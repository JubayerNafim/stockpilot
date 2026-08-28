<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'order_id',
    'woocommerce_item_id',
    'woocommerce_product_id',
    'product_id',
    'product_name',
    'sku',
    'quantity',
    'price',
    'line_total',
    'sort',
])]
class OrderItem extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'sp_order_items';

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'price' => 'float',
            'line_total' => 'float',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function components(): HasMany
    {
        return $this->hasMany(OrderItemComponent::class);
    }
}
