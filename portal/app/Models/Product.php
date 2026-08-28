<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'organization_id',
    'store_id',
    'woo_product_id',
    'source',
    'name',
    'sku',
    'product_type',
    'publish_status',
    'price',
    'cost',
    'quantity_on_hand',
    'quantity_reserved',
    'low_stock_threshold',
    'regular_price',
    'sale_price',
    'stock_status',
    'stock_quantity',
    'categories',
    'image_url',
    'attributes',
    'payload',
    'synced_at',
    'is_active',
])]
class Product extends \Illuminate\Database\Eloquent\Model
{
    use SoftDeletes;

    protected $table = 'sp_products';

    protected function casts(): array
    {
        return [
            'price' => 'float',
            'cost' => 'float',
            'quantity_on_hand' => 'float',
            'quantity_reserved' => 'float',
            'low_stock_threshold' => 'float',
            'regular_price' => 'float',
            'sale_price' => 'float',
            'stock_quantity' => 'float',
            'categories' => 'array',
            'attributes' => 'array',
            'payload' => 'array',
            'synced_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /** Finished-goods stock value at the product's own cost. */
    public function stockValue(): float
    {
        return round($this->quantity_on_hand * ($this->cost ?? 0), 4);
    }

    /** Available (unreserved) finished-goods units. */
    public function available(): float
    {
        return round($this->quantity_on_hand - $this->quantity_reserved, 4);
    }

    public function isLowStock(): bool
    {
        return $this->low_stock_threshold > 0
            && $this->available() <= $this->low_stock_threshold;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(StoreConnection::class, 'store_id');
    }

    public function bomLines(): HasMany
    {
        return $this->hasMany(BomLine::class);
    }

    public function components(): HasMany
    {
        return $this->hasMany(OrderItemComponent::class);
    }

    public function hasRecipe(): bool
    {
        return $this->bomLines()->exists();
    }

    /** Per-unit material cost = sum(bom qty × item cost) + sub-product component costs. */
    public function materialCost(): float
    {
        return round(
            $this->bomLines()->with(['item', 'componentProduct'])->get()->sum(
                fn (BomLine $line) => $line->component_type === 'product'
                    ? $line->quantity * ($line->componentProduct?->cost ?? 0)
                    : $line->quantity * $line->item?->cost_price,
            ),
            4,
        );
    }
}
