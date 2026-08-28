<?php

namespace App\Services;

use App\Models\Item;
use App\Models\ItemMovement;
use App\Models\Order;
use App\Models\Product;

/**
 * Re-sync tool for orders after recipe changes or cache drift.
 *
 * - dry_run: report, no writes.
 * - apply:    reconcile item/product caches against the ledger (authoritative)
 *             via append-only `sync_fix` movements. Never edits past ledger rows.
 */
class OrderRecomposer
{
    public function __construct(private BomExpander $expander)
    {
    }

    public function recompose(Order $order, bool $apply): array
    {
        return [
            'recipe_drift' => $this->recipeDrift($order),
            'cache_reconciliation' => $this->cacheReconciliation($order, $apply),
        ];
    }

    /** Expected components under the CURRENT recipe vs the snapshot at reserve time. */
    private function recipeDrift(Order $order): array
    {
        $expected = [];
        $meta = [];

        $result = $this->expander->expandForOrder($order);
        foreach ($result['rows'] as $row) {
            $key = $this->entityKey($row['item_id'] ?? null, $row['component_product_id'] ?? null);
            $expected[$key] = round(($expected[$key] ?? 0) + $row['total_quantity'], 4);
            $meta[$key] = $this->entityMeta($row['item_id'] ?? null, $row['component_product_id'] ?? null);
        }

        $actual = [];
        foreach ($order->components as $component) {
            $key = $this->entityKey($component->item_id, $component->component_product_id);
            $actual[$key] = round(($actual[$key] ?? 0) + $component->total_quantity, 4);
            $meta[$key] ??= $this->entityMeta($component->item_id, $component->component_product_id);
        }

        $diff = [];

        foreach (array_unique(array_merge(array_keys($expected), array_keys($actual))) as $key) {
            $e = round($expected[$key] ?? 0, 4);
            $a = round($actual[$key] ?? 0, 4);

            if (abs($e - $a) > 0.0001) {
                $m = $meta[$key] ?? ['type' => 'item', 'id' => null];

                $diff[] = [
                    'entity_type' => $m['type'],
                    'entity_id' => $m['id'],
                    'entity_name' => $m['id'] ? ($m['type'] === 'product'
                        ? Product::find($m['id'])?->name
                        : Item::find($m['id'])?->name) : null,
                    'expected_total' => $e,
                    'snapshot_total' => $a,
                ];
            }
        }

        return $diff;
    }

    /** Compare stock caches against the sum of their ledger movements. */
    private function cacheReconciliation(Order $order, bool $apply): array
    {
        $changes = [];

        foreach ($order->components as $component) {
            $isProduct = $component->component_product_id !== null;
            $entity = $isProduct
                ? Product::find($component->component_product_id)
                : $component->item;

            if (! $entity) {
                continue;
            }

            $ledger = $isProduct
                ? ItemMovement::where('product_id', $entity->id)
                : ItemMovement::where('item_id', $entity->id);

            $ledgerOnHand = (float) (clone $ledger)->sum('qty_delta');
            $ledgerReserved = (float) (clone $ledger)->sum('reserved_delta');
            $diffOnHand = round($ledgerOnHand - $entity->quantity_on_hand, 4);
            $diffReserved = round($ledgerReserved - $entity->quantity_reserved, 4);

            if (abs($diffOnHand) < 0.0001 && abs($diffReserved) < 0.0001) {
                continue;
            }

            $changes[] = [
                'entity_type' => $isProduct ? 'product' : 'item',
                'entity_id' => $entity->id,
                'entity_name' => $entity->name,
                'cache_on_hand' => (float) $entity->quantity_on_hand,
                'ledger_on_hand' => round($ledgerOnHand, 4),
                'cache_reserved' => (float) $entity->quantity_reserved,
                'ledger_reserved' => round($ledgerReserved, 4),
            ];

            if (! $apply) {
                continue;
            }

            if (abs($diffOnHand) >= 0.0001) {
                $entity->quantity_on_hand = round($entity->quantity_on_hand + $diffOnHand, 4);
            }

            if (abs($diffReserved) >= 0.0001) {
                $entity->quantity_reserved = round($entity->quantity_reserved + $diffReserved, 4);
            }

            $entity->save();

            ItemMovement::create([
                'organization_id' => $order->organization_id,
                'item_id' => $isProduct ? null : $entity->id,
                'product_id' => $isProduct ? $entity->id : null,
                'movement_type' => 'sync_fix',
                'qty_delta' => $diffOnHand,
                'reserved_delta' => $diffReserved,
                'on_hand_after' => $entity->quantity_on_hand,
                'reserved_after' => $entity->quantity_reserved,
                'ref_type' => 'sync_fix',
                'ref_id' => $order->id,
                'reason' => "Cache reconciliation for order #{$order->woocommerce_number}",
            ]);
        }

        return $changes;
    }

    private function entityKey(?int $itemId, ?int $productId): string
    {
        return $itemId !== null ? 'i'.$itemId : 'p'.$productId;
    }

    /** @return array{type: 'item'|'product', id: int|null} */
    private function entityMeta(?int $itemId, ?int $productId): array
    {
        return $itemId !== null
            ? ['type' => 'item', 'id' => $itemId]
            : ['type' => 'product', 'id' => $productId];
    }
}
