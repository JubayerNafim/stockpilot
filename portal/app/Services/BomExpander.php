<?php

namespace App\Services;

use App\Models\BomLine;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;

/**
 * Expands a sellable product into the stockable entities it consumes.
 *
 * Every order line that maps to a product produces:
 *   1. the product's OWN stock (the physical sellable unit), and
 *   2. each recipe line — an item (leaf) or a sub-product (recursed).
 *
 * Components are merged by (order_item_id, entity) so the same entity reached
 * via multiple lines counts once with summed quantities (no double-count).
 */
class BomExpander
{
    private const MAX_DEPTH = 10;

    /**
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     unmapped: list<int>,
     *     cycle_error: bool,
     * }
     */
    public function expandForOrder(Order $order): array
    {
        $rows = [];
        $unmapped = [];
        $cycle = false;

        $order->items()
            ->with(['product.bomLines.item', 'product.bomLines.componentProduct'])
            ->each(function (OrderItem $orderItem) use (&$rows, &$unmapped, &$cycle): void {
                $product = $orderItem->product;

                if (! $product) {
                    $unmapped[] = $orderItem->id;

                    return;
                }

                // Items listed DIRECTLY on the sellable product's own recipe are
                // authoritative for this order line. A combo that explicitly says
                // "Courier poly × 1" owns that poly — the poly that its sub-products
                // (belt, wallet) also carry in their recipes must not stack on top
                // (that was the combo 3x over-reservation bug). Items the combo
                // does NOT list directly still add up from every sub-product.
                $ownedItemIds = $product->bomLines
                    ->filter(fn (BomLine $l) => $l->component_type === 'item' && $l->item_id !== null)
                    ->pluck('item_id')
                    ->map(fn ($v) => (int) $v)
                    ->all();

                $this->expandProduct($product, $product->id, 1.0, (float) $orderItem->quantity, [], $rows, $cycle, $orderItem->id, $ownedItemIds, true);
            });

        return [
            'rows' => array_values($rows),
            'unmapped' => $unmapped,
            'cycle_error' => $cycle,
        ];
    }

    /**
     * @param  list<int>  $path  product ids on the current branch (cycle guard)
     * @param  array<string, array<string, mixed>>  $rows  merged by "orderItemId:key"
     * @param  list<int>  $ownedItemIds  item ids the top-level product lists directly (authoritative)
     */
    private function expandProduct(
        Product $p,
        int $sellableId,
        float $factor,
        float $orderQty,
        array $path,
        array &$rows,
        bool &$cycle,
        int $orderItemId,
        array $ownedItemIds = [],
        bool $isTop = false,
    ): void {
        if (in_array($p->id, $path, true)) {
            $cycle = true;

            return;
        }

        if (count($path) >= self::MAX_DEPTH) {
            $cycle = true;

            return;
        }

        $path[] = $p->id;

        // The physical sellable unit: the product's own stock is consumed too.
        $this->addRow($rows, $orderItemId, $sellableId, null, $p->id, $factor, $orderQty);

        foreach ($p->bomLines as $line) {
            if ($line->component_type === 'product') {
                $sub = $line->componentProduct;

                if (! $sub) {
                    continue;
                }

                $this->expandProduct($sub, $sellableId, (float) $line->quantity * $factor, $orderQty, $path, $rows, $cycle, $orderItemId, $ownedItemIds, false);
            } else {
                if (! $line->item) {
                    continue; // soft-deleted item
                }

                // The top product's own recipe line already covers this item; a
                // sub-product's copy of the same item is the packing overhead the
                // combo shouldn't pay twice (or three times) for.
                if (! $isTop && in_array((int) $line->item_id, $ownedItemIds, true)) {
                    continue;
                }

                $this->addRow($rows, $orderItemId, $sellableId, $line->item_id, null, (float) $line->quantity * $factor, $orderQty);
            }
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     */
    private function addRow(
        array &$rows,
        int $orderItemId,
        int $sellableId,
        ?int $itemId,
        ?int $componentProductId,
        float $perUnit,
        float $orderQty,
    ): void {
        $key = $itemId !== null ? "i{$itemId}" : "p{$componentProductId}";
        $index = $orderItemId.':'.$key;

        if (isset($rows[$index])) {
            $rows[$index]['quantity_per_unit'] = round($rows[$index]['quantity_per_unit'] + $perUnit, 4);
            $rows[$index]['total_quantity'] = round($rows[$index]['total_quantity'] + $perUnit * $orderQty, 4);

            return;
        }

        $rows[$index] = [
            'order_item_id' => $orderItemId,
            'product_id' => $sellableId,
            'item_id' => $itemId,
            'component_product_id' => $componentProductId,
            'quantity_per_unit' => round($perUnit, 4),
            'order_quantity' => (float) $orderQty,
            'total_quantity' => round($perUnit * $orderQty, 4),
        ];
    }

    /** Per-unit material cost of a product (item lines + sub-product costs). */
    public function productCost(int $productId): float
    {
        return Product::findOrFail($productId)
            ->bomLines()
            ->with(['item', 'componentProduct'])
            ->get()
            ->sum(fn (BomLine $line) => $line->component_type === 'product'
                ? $line->quantity * ($line->componentProduct?->cost ?? 0)
                : $line->quantity * ($line->item?->cost_price ?? 0));
    }
}
