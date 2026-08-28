<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockWarning;
use App\Models\StoreConnection;
use App\Services\AssetValuation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private AssetValuation $valuation)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $orgId = $request->user()->organization_id;

        $lowStock = Item::where('organization_id', $orgId)
            ->whereColumn('quantity_on_hand', '<=', 'low_stock_threshold')
            ->where('low_stock_threshold', '>', 0)
            ->orderBy('quantity_on_hand')
            ->limit(10)
            ->get();

        $lowStockProducts = Product::where('organization_id', $orgId)
            ->whereRaw('(quantity_on_hand - COALESCE(quantity_reserved, 0)) <= low_stock_threshold')
            ->where('low_stock_threshold', '>', 0)
            ->orderBy('quantity_on_hand')
            ->limit(10)
            ->get();

        $ordersByStatus = Order::where('organization_id', $orgId)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->orderByDesc('count')
            ->get();

        $recentOrders = Order::where('organization_id', $orgId)
            ->orderByDesc('order_created_at')
            ->limit(8)
            ->get(['id', 'woocommerce_number', 'customer_name', 'status', 'reservation_state', 'total', 'order_created_at']);

        return response()->json([
            'asset_value' => $this->valuation->total($orgId),
            'asset_value_by_category' => $this->valuation->byCategory($orgId),
            'low_stock' => $lowStock->map(fn (Item $item) => [
                'id' => $item->id,
                'name' => $item->name,
                'quantity_on_hand' => (float) $item->quantity_on_hand,
                'low_stock_threshold' => (float) $item->low_stock_threshold,
            ]),
            'low_stock_products' => $lowStockProducts->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'quantity_on_hand' => (float) $p->quantity_on_hand,
                'quantity_reserved' => (float) $p->quantity_reserved,
                'available' => $p->available(),
                'low_stock_threshold' => (float) $p->low_stock_threshold,
            ]),
            'active_warnings' => StockWarning::where('organization_id', $orgId)
                ->where('status', 'active')
                ->count(),
            'orders_by_status' => $ordersByStatus,
            'recent_orders' => $recentOrders,
            'store_health' => [
                'connected' => StoreConnection::where('organization_id', $orgId)->where('is_active', true)->count(),
                'total' => StoreConnection::where('organization_id', $orgId)->count(),
            ],
        ]);
    }
}
