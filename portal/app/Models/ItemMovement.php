<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id',
    'item_id',
    'product_id',
    'movement_type',
    'qty_delta',
    'reserved_delta',
    'on_hand_after',
    'reserved_after',
    'ref_type',
    'ref_id',
    'reversal_of',
    'cost_price_at_movement',
    'reason',
    'created_by',
])]
class ItemMovement extends \Illuminate\Database\Eloquent\Model
{
    public const UPDATED_AT = null;

    protected $table = 'sp_item_movements';

    protected function casts(): array
    {
        return [
            'qty_delta' => 'float',
            'reserved_delta' => 'float',
            'on_hand_after' => 'float',
            'reserved_after' => 'float',
            'cost_price_at_movement' => 'float',
            'created_at' => 'datetime',
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

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
