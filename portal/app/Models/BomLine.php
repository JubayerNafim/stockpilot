<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'product_id', 'item_id', 'component_product_id', 'component_type', 'quantity'])]
class BomLine extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'sp_bom_lines';

    protected function casts(): array
    {
        return ['quantity' => 'float'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Raw-material component (component_type = 'item'). */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** Sub-product component (component_type = 'product'). */
    public function componentProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'component_product_id');
    }
}
