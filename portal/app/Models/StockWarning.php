<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id',
    'item_id',
    'product_id',
    'warning_type',
    'status',
    'triggered_at',
    'cleared_at',
    'cooldown_until',
    'email_sent',
    'email_sent_at',
])]
class StockWarning extends \Illuminate\Database\Eloquent\Model
{
    public const CREATED_AT = 'triggered_at';

    public const UPDATED_AT = null;

    protected $table = 'sp_stock_warnings';

    protected function casts(): array
    {
        return [
            'triggered_at' => 'datetime',
            'cleared_at' => 'datetime',
            'cooldown_until' => 'datetime',
            'email_sent' => 'boolean',
            'email_sent_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Human-readable label for the warned entity (item or product). */
    public function entityLabel(): string
    {
        return $this->product_id
            ? ($this->product?->name ?? 'Product #'.$this->product_id)
            : ($this->item?->name ?? 'Item #'.$this->item_id);
    }

    /** Current quantity on hand of the warned entity. */
    public function entityOnHand(): float
    {
        return $this->product_id
            ? (float) ($this->product?->quantity_on_hand ?? 0)
            : (float) ($this->item?->quantity_on_hand ?? 0);
    }

    /** Warning threshold of the warned entity. */
    public function entityThreshold(): float
    {
        return $this->product_id
            ? (float) ($this->product?->low_stock_threshold ?? 0)
            : (float) ($this->item?->low_stock_threshold ?? 0);
    }
}
