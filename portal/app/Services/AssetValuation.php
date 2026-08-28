<?php

namespace App\Services;

use App\Models\AssetSnapshot;
use App\Models\Item;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Real asset value = Σ (quantity_on_hand × cost_price) over items AND products.
 * Finished goods and raw materials are distinct physical stock — both count.
 */
class AssetValuation
{
    public function total(int $organizationId): float
    {
        return round((float) Item::where('organization_id', $organizationId)
            ->sum(DB::raw('quantity_on_hand * cost_price'))
            + (float) Product::where('organization_id', $organizationId)
            ->sum(DB::raw('COALESCE(quantity_on_hand, 0) * COALESCE(cost, 0)')), 4);
    }

    public function byCategory(int $organizationId): array
    {
        return Item::query()
            ->where('organization_id', $organizationId)
            ->selectRaw('COALESCE(category_id, 0) as category_id')
            ->selectRaw('SUM(quantity_on_hand * cost_price) as value')
            ->selectRaw('SUM(quantity_on_hand) as qty')
            ->groupBy('category_id')
            ->get()
            ->map(function ($row) use ($organizationId) {
                $category = $row->category_id ? \App\Models\Category::find($row->category_id) : null;

                return [
                    'category_id' => $row->category_id,
                    'category_name' => $category?->name ?? 'Uncategorized',
                    'value' => round((float) $row->value, 4),
                    'quantity' => round((float) $row->qty, 4),
                ];
            })
            ->values()
            ->toArray();
    }

    public function captureSnapshot(int $organizationId): AssetSnapshot
    {
        return AssetSnapshot::create([
            'organization_id' => $organizationId,
            'total_value' => $this->total($organizationId),
            'per_category' => $this->byCategory($organizationId),
        ]);
    }
}
