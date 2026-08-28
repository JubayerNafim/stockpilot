<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'item_id', 'adjustment_type', 'quantity', 'reason', 'created_by'])]
class StockAdjustment extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'sp_stock_adjustments';

    protected function casts(): array
    {
        return ['quantity' => 'float'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
