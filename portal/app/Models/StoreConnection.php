<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

#[Fillable([
    'organization_id',
    'name',
    'store_url',
    'api_key',
    'api_secret',
    'plugin_version',
    'is_active',
    'revoked_at',
    'last_seen_at',
    'last_sync_at',
    'last_error',
    'last_error_at',
    'sync_count',
])]
class StoreConnection extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'sp_store_connections';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'revoked_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_sync_at' => 'datetime',
            'last_error_at' => 'datetime',
        ];
    }

    /** Setting api_key stores a SHA-256 hash; the plain key is never kept. */
    protected function apiKey(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => ['api_key_hash' => $value ? hash('sha256', $value) : null],
        );
    }

    /** api_secret is stored encrypted (the portal must recompute HMACs). */
    protected function apiSecret(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->api_secret_encrypted ? Crypt::decryptString($this->api_secret_encrypted) : null,
            set: fn (?string $value) => ['api_secret_encrypted' => $value ? Crypt::encryptString($value) : null],
        );
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'store_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
