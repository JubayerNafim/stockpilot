<?php

namespace App\Services;

use App\Models\Setting;

class SettingService
{
    public function get(int $organizationId, string $key, mixed $default = null): mixed
    {
        $row = Setting::where('organization_id', $organizationId)->where('key', $key)->first();

        return $row?->value ?? $default;
    }

    public function set(int $organizationId, string $key, mixed $value): void
    {
        Setting::updateOrCreate(
            ['organization_id' => $organizationId, 'key' => $key],
            ['value' => $value],
        );
    }

    public function all(int $organizationId): array
    {
        return Setting::where('organization_id', $organizationId)
            ->get()
            ->pluck('value', 'key')
            ->toArray();
    }
}
