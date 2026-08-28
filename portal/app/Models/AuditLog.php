<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id',
    'user_id',
    'action',
    'entity_type',
    'entity_id',
    'before',
    'after',
])]
class AuditLog extends \Illuminate\Database\Eloquent\Model
{
    public const UPDATED_AT = null;

    protected $table = 'sp_audit_log';

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
