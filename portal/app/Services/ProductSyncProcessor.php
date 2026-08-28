<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StoreConnection;

/**
 * Upserts / soft-deletes WooCommerce product metadata into the portal.
 * Metadata only — never touches inventory or BOM recipes.
 */
class ProductSyncProcessor
{
    public function syncProduct(array $data, StoreConnection $store, bool $isDelete = false): void
    {
        $wooProductId = $data['id'] ?? null;

        if (! $wooProductId) {
            return;
        }

        $product = Product::where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)
            ->where('woo_product_id', $wooProductId)
            ->first();

        if ($isDelete) {
            if ($product) {
                $product->update(['is_active' => false]);
            }

            return;
        }

        $values = [
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'woo_product_id' => $wooProductId,
            'source' => 'woo',
            'name' => $data['name'] ?? 'Unnamed product',
            'sku' => $data['sku'] ?? null,
            'product_type' => $data['type'] ?? 'simple',
            'publish_status' => $data['status'] ?? 'publish',
            'price' => $data['price'] ?? 0,
            'regular_price' => $data['regular_price'] ?? null,
            'sale_price' => $data['sale_price'] ?? null,
            'stock_status' => $data['stock_status'] ?? null,
            'stock_quantity' => $data['stock_quantity'] ?? null,
            'categories' => $data['categories'] ?? [],
            'image_url' => $data['image'] ?? null,
            'attributes' => $data['attributes'] ?? [],
            'payload' => $data,
            'synced_at' => now(),
            'is_active' => true,
        ];

        if ($product) {
            $product->fill($values)->save();
        } else {
            Product::create($values);
        }
    }
}
