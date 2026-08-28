<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ItemMovement;
use App\Models\Order;
use App\Models\OrderItemComponent;
use App\Models\WebhookEvent;
use App\Services\InventoryEngine;
use App\Services\OrderRecomposer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    public function __construct(private OrderRecomposer $recomposer)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $orgId = $request->user()->organization_id;

        $query = Order::where('organization_id', $orgId)
            ->withCount('components')
            ->orderByDesc('order_created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('store_id')) {
            $query->where('store_id', $request->store_id);
        }

        if ($request->filled('reservation_state')) {
            $query->where('reservation_state', $request->reservation_state);
        }

        if ($request->boolean('mismatch')) {
            $query->where('sync_status', 'mismatch');
        }

        if ($request->boolean('unmapped')) {
            $query->where('sync_status', 'unmapped');
        }

        if ($request->filled('from')) {
            $query->where('order_created_at', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->where('order_created_at', '<=', $request->to);
        }

        $orders = $query->paginate((int) $request->get('per_page', 25));

        $orders->getCollection()->transform(fn (Order $order) => $this->summary($order));

        return response()->json($orders);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $this->assertSameOrg($request, $order);

        $order->load(['store', 'items.product', 'components.item', 'components.componentProduct', 'statusEvents']);

        return response()->json([
            'order' => $this->summary($order),
            'items' => $order->items,
            'components' => $order->components,
            'status_events' => $order->statusEvents,
        ]);
    }

    public function components(Request $request, Order $order): JsonResponse
    {
        $this->assertSameOrg($request, $order);

        $components = $order->components()->with('item.category', 'componentProduct')->get();

        return response()->json(['components' => $components]);
    }

    public function movements(Request $request, Order $order): JsonResponse
    {
        $this->assertSameOrg($request, $order);

        $movements = ItemMovement::where('ref_type', 'order')
            ->where('ref_id', $order->id)
            ->with('item', 'product')
            ->orderBy('created_at')
            ->get();

        return response()->json(['movements' => $movements]);
    }

    public function recompute(Request $request, Order $order): JsonResponse
    {
        $this->assertSameOrg($request, $order);

        $apply = $request->boolean('apply');
        $result = $this->recomposer->recompose($order, $apply);

        return response()->json([
            'apply' => $apply,
            'recipe_drift' => $result['recipe_drift'],
            'cache_reconciliation' => $result['cache_reconciliation'],
            'drift_count' => count($result['recipe_drift']),
            'reconciliation_count' => count($result['cache_reconciliation']),
        ]);
    }

    /**
     * Re-run an existing order's stored payload through the inventory engine.
     * Idempotent: an already-reserved order stays reserved, an already-deducted
     * order stays confirmed. Use it to fix orders that were synced before a
     * status-map change (e.g. an order stuck at reservation_state 'none').
     */
    public function reprocess(Request $request, Order $order): JsonResponse
    {
        $this->assertSameOrg($request, $order);

        return response()->json(['result' => $this->runReprocess($order)]);
    }

    /** Delete an order after reversing its stock impact (admin). */
    public function destroy(Request $request, Order $order): JsonResponse
    {
        $this->assertSameOrg($request, $order);

        $this->destroyOrder($order);

        return response()->json(['ok' => true]);
    }

    /** Apply one action (reprocess / dry-run / apply reconciliation / delete) to many orders. */
    public function bulk(Request $request): JsonResponse
    {
        $orgId = $request->user()->organization_id;

        $data = $request->validate([
            'action' => 'required|in:reprocess,recompute_dry,recompute_apply,delete',
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        if ($data['action'] === 'delete' && $request->user()->role !== 'admin') {
            abort(403, 'Only admins can delete orders.');
        }

        $orders = Order::where('organization_id', $orgId)
            ->whereIn('id', $data['ids'])
            ->orderBy('id')
            ->get();

        $results = [];

        foreach ($orders as $order) {
            try {
                if ($data['action'] === 'delete') {
                    $this->destroyOrder($order);

                    $results[] = ['id' => $order->id, 'number' => $order->woocommerce_number, 'status' => 'ok', 'deleted' => true];
                } else {
                    $results[] = ['id' => $order->id, 'number' => $order->woocommerce_number, 'status' => 'ok']
                        + $this->bulkApply($order, $data['action']);
                }
            } catch (\Throwable $e) {
                $results[] = ['id' => $order->id, 'number' => $order->woocommerce_number, 'status' => 'error', 'error' => $e->getMessage()];
            }
        }

        return response()->json(['results' => $results]);
    }

    // ------------------------------------------------------------------
    // Helpers (shared by the single-order and bulk actions)
    // ------------------------------------------------------------------

    private function runReprocess(Order $order): array
    {
        if (! is_array($order->payload) || ! $order->store) {
            abort(422, 'This order has no stored payload or store connection to reprocess against.');
        }

        $store = $order->store;

        $event = WebhookEvent::create([
            'organization_id' => $order->organization_id,
            'store_id' => $store->id,
            'woocommerce_order_id' => $order->woocommerce_id,
            'event_id' => 'reprocess-'.$order->id.'-'.now()->getTimestamp(),
            'nonce' => Str::random(32),
            'event_type' => 'order.sync',
            'wc_status' => $order->status,
            'payload' => json_encode(['order' => $order->payload]),
            'status' => 'received',
        ]);

        return app(InventoryEngine::class)->processOrderEvent($order->payload, $store, $event);
    }

    private function bulkApply(Order $order, string $action): array
    {
        return match ($action) {
            'reprocess' => ['result' => $this->runReprocess($order)],
            'recompute_dry' => $this->recomposeResult($order, false),
            'recompute_apply' => $this->recomposeResult($order, true),
            default => [],
        };
    }

    private function recomposeResult(Order $order, bool $apply): array
    {
        $result = $this->recomposer->recompose($order, $apply);

        return [
            'drift_count' => count($result['recipe_drift']),
            'reconciliation_count' => count($result['cache_reconciliation']),
        ];
    }

    /**
     * Remove an order after reversing its stock impact. Outstanding reservations
     * are released and confirmed quantities restocked (via a synthetic
     * 'cancelled' event through the engine), then the order and its ledger rows
     * are deleted. The webhook_event row is left as an audit trail.
     */
    private function destroyOrder(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $payload = is_array($order->payload) ? $order->payload : null;

            if ($order->store && $payload && in_array($order->reservation_state, ['reserved', 'partial', 'confirmed'], true)) {
                $event = WebhookEvent::create([
                    'organization_id' => $order->organization_id,
                    'store_id' => $order->store_id,
                    'woocommerce_order_id' => $order->woocommerce_id,
                    'event_id' => 'delete-'.$order->id.'-'.now()->getTimestamp(),
                    'nonce' => Str::random(32),
                    'event_type' => 'order.sync',
                    'wc_status' => 'cancelled',
                    'payload' => json_encode(['order' => ['id' => $order->woocommerce_id]]),
                    'status' => 'received',
                ]);

                $payload['status'] = 'cancelled';
                app(InventoryEngine::class)->processOrderEvent($payload, $order->store, $event);
            }

            // The ledger is self-referencing (release/return rows point at the
            // original reserve/confirm via reversal_of). Clear those pointers
            // before deleting, or MySQL's FK rejects the DELETE.
            ItemMovement::where('ref_type', 'order')
                ->where('ref_id', $order->id)
                ->whereNotNull('reversal_of')
                ->update(['reversal_of' => null]);

            ItemMovement::where('ref_type', 'order')->where('ref_id', $order->id)->delete();
            $order->delete();
        });
    }

    private function assertSameOrg(Request $request, Order $order): void
    {
        abort_if($order->organization_id !== $request->user()->organization_id, 404);
    }

    private function summary(Order $order): array
    {
        return [
            'id' => $order->id,
            'woocommerce_id' => $order->woocommerce_id,
            'number' => $order->woocommerce_number,
            'status' => $order->status,
            'reservation_state' => $order->reservation_state,
            'customer_name' => $order->customer_name,
            'customer_email' => $order->customer_email,
            'currency' => $order->currency,
            'total' => (float) $order->total,
            'refund_total' => (float) $order->refund_total,
            'return_charge' => (float) $order->return_charge,
            'store_id' => $order->store_id,
            'store_name' => $order->store?->name,
            'sync_status' => $order->sync_status,
            'component_count' => $order->components_count ?? $order->components()->count(),
            'order_created_at' => $order->order_created_at,
            'reserved_at' => $order->reserved_at,
            'confirmed_at' => $order->confirmed_at,
            'released_at' => $order->released_at,
            'returned_at' => $order->returned_at,
        ];
    }
}
