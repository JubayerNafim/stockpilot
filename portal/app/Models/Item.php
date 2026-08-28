<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'organization_id',
    'category_id',
    'name',
    'sku',
    'unit',
    'cost_price',
    'quantity_on_hand',
    'quantity_reserved',
    'low_stock_threshold',
    'is_active',
])]
class Item extends \Illuminate\Database\Eloquent\Model
{
    use SoftDeletes;

    protected $table = 'sp_items';

    protected function casts(): array
    {
        return [
            'quantity_on_hand' => 'float',
            'quantity_reserved' => 'float',
            'cost_price' => 'float',
            'low_stock_threshold' => 'float',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(ItemMovement::class);
    }

    public function bomLines(): HasMany
    {
        return $this->hasMany(BomLine::class);
    }

    public function components(): HasMany
    {
        return $this->hasMany(OrderItemComponent::class);
    }

    public function warnings(): HasMany
    {
        return $this->hasMany(StockWarning::class);
    }

    /** Available = on-hand minus reserved. */
    public function available(): Attribute
    {
        return Attribute::get(fn () => round($this->quantity_on_hand - $this->quantity_reserved, 4));
    }

    /** Real asset value of this item at its current cost. */
    public function assetValue(): Attribute
    {
        return Attribute::get(fn () => round($this->quantity_on_hand * $this->cost_price, 4));
    }

    public function isLowStock(): bool
    {
        return $this->low_stock_threshold > 0
            && $this->quantity_on_hand <= $this->low_stock_threshold;
    }
}
