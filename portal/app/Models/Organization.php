<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'currency',
    'timezone',
    'low_stock_email',
    'settings',
])]
class Organization extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'sp_organizations';

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    public function stores(): HasMany
    {
        return $this->hasMany(StoreConnection::class);
    }
}
