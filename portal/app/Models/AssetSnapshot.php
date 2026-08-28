<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'captured_at', 'total_value', 'per_category'])]
class AssetSnapshot extends \Illuminate\Database\Eloquent\Model
{
    public const CREATED_AT = 'captured_at';

    public const UPDATED_AT = null;

    protected $table = 'sp_asset_snapshots';

    protected function casts(): array
    {
        return [
            'total_value' => 'float',
            'per_category' => 'array',
            'captured_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
