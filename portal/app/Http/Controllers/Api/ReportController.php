<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AssetSnapshot;
use App\Models\Item;
use App\Models\ItemMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockWarning;
use App\Services\AssetValuation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function __construct(private AssetValuation $valuation)
    {
    }

    public function assetValue(Request $request): JsonResponse
    {
        $orgId = $request->user()->organization_id;

        $byItem = Item::where('organization_id', $orgId)
            ->with('category')
            ->orderByDesc('quantity_on_hand')
            ->get()
            ->map(fn (Item $item) => [
                'id' => $item->id,
                'name' => $item->name,
                'sku' => $item->sku,
                'category' => $item->category?->name,
                'quantity_on_hand' => (float) $item->quantity_on_hand,
                'cost_price' => (float) $item->cost_price,
                'value' => round($item->quantity_on_hand * $item->cost_price, 4),
            ]);

        // Finished goods have their own physical stock — value them at their
        // own cost, separate from raw-material items.
        $byProduct = Product::where('organization_id', $orgId)
            ->orderByDesc('quantity_on_hand')
            ->get()
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'quantity_on_hand' => (float) $product->quantity_on_hand,
                'quantity_reserved' => (float) $product->quantity_reserved,
                'cost' => (float) ($product->cost ?? 0),
                'value' => $product->stockValue(),
            ]);

        $materialsTotal = round((float) Item::where('organization_id', $orgId)
            ->sum(DB::raw('quantity_on_hand * cost_price')), 4);

        $finishedTotal = round((float) Product::where('organization_id', $orgId)
            ->sum(DB::raw('COALESCE(quantity_on_hand, 0) * COALESCE(cost, 0)')), 4);

        $snapshots = AssetSnapshot::where('organization_id', $orgId)
            ->orderByDesc('captured_at')
            ->limit(90)
            ->get();

        return response()->json([
            'total' => $this->valuation->total($orgId),
            'materials_total' => $materialsTotal,
            'finished_total' => $finishedTotal,
            'by_category' => $this->valuation->byCategory($orgId),
            'by_item' => $byItem,
            'by_product' => $byProduct,
            'snapshots' => $snapshots->map(fn ($s) => [
                'captured_at' => $s->captured_at,
                'total_value' => (float) $s->total_value,
            ]),
        ]);
    }

    public function consumption(Request $request): JsonResponse
    {
        $orgId = $request->user()->organization_id;

        $query = ItemMovement::where('organization_id', $orgId)
            ->where('movement_type', 'confirm')
            ->where('qty_delta', '<', 0)
            ->with('item')
            ->orderByDesc('created_at');

        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->to);
        }

        $rows = $query->get()->groupBy('item_id')->map(function ($group, $itemId) {
            $item = $group->first()->item;

            return [
                'item_id' => (int) $itemId,
                'item_name' => $item?->name ?? 'Unknown',
                'quantity_consumed' => round($group->sum('qty_delta') * -1, 4),
                'cost' => round($group->sum(fn ($m) => $m->qty_delta * -1 * ($m->cost_price_at_movement ?? 0)), 4),
                'events' => $group->count(),
            ];
        })->sortByDesc('quantity_consumed')->values();

        return response()->json(['consumption' => $rows]);
    }

    public function orders(Request $request): JsonResponse
    {
        $orgId = $request->user()->organization_id;

        $byStatus = Order::where('organization_id', $orgId)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->get();

        $byReservation = Order::where('organization_id', $orgId)
            ->selectRaw('reservation_state, COUNT(*) as count')
            ->groupBy('reservation_state')
            ->get();

        $totals = Order::where('organization_id', $orgId)
            ->selectRaw('COALESCE(SUM(total),0) as revenue, COALESCE(SUM(refund_total),0) as refunds, COUNT(*) as total')
            ->first();

        return response()->json([
            'by_status' => $byStatus,
            'by_reservation_state' => $byReservation,
            'totals' => $totals,
        ]);
    }

    public function lowStock(Request $request): JsonResponse
    {
        $orgId = $request->user()->organization_id;

        $items = Item::where('organization_id', $orgId)
            ->with('category')
            ->whereColumn('quantity_on_hand', '<=', 'low_stock_threshold')
            ->where('low_stock_threshold', '>', 0)
            ->orderBy('quantity_on_hand')
            ->get()
            ->map(fn (Item $item) => [
                'id' => $item->id,
                'name' => $item->name,
                'sku' => $item->sku,
                'category' => $item->category?->name,
                'quantity_on_hand' => (float) $item->quantity_on_hand,
                'quantity_reserved' => (float) $item->quantity_reserved,
                'low_stock_threshold' => (float) $item->low_stock_threshold,
            ]);

        $warnings = StockWarning::where('organization_id', $orgId)
            ->where('status', 'active')
            ->with('item')
            ->latest('triggered_at')
            ->get();

        return response()->json(['items' => $items, 'warnings' => $warnings]);
    }

    public function unmappedOrderItems(Request $request): JsonResponse
    {
        $orgId = $request->user()->organization_id;

        $items = \App\Models\OrderItem::whereHas('order', fn ($q) => $q->where('organization_id', $orgId))
            ->whereNull('product_id')
            ->with('order:id,woocommerce_number,order_created_at')
            ->orderByDesc('created_at')
            ->limit(500)
            ->get()
            ->map(fn ($oi) => [
                'id' => $oi->id,
                'order_number' => $oi->order?->woocommerce_number,
                'product_name' => $oi->product_name,
                'sku' => $oi->sku,
                'quantity' => (float) $oi->quantity,
                'line_total' => (float) $oi->line_total,
                'order_created_at' => $oi->order?->order_created_at,
            ]);

        return response()->json(['items' => $items]);
    }
}
