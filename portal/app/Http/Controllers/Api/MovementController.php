<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ItemMovement;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Global inventory activity ledger — every reserve/confirm/release/return/
 * adjustment/initial movement for BOTH items and products, with the order or
 * reason that caused it. This is how an admin can see exactly which old order
 * deducted (or added) stock and decide what to delete later.
 */
class MovementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $orgId = $request->user()->organization_id;

        $query = ItemMovement::where('organization_id', $orgId)
            ->with('item:id,name,sku', 'product:id,name,sku')
            ->orderByDesc('created_at');

        // Entity scope: 'item' | 'product'
        $entityType = $request->get('entity');
        if ($entityType === 'item') {
            $query->whereNotNull('item_id');
        } elseif ($entityType === 'product') {
            $query->whereNotNull('product_id');
        }

        if ($request->filled('movement_type')) {
            $query->where('movement_type', $request->movement_type);
        }

        if ($request->filled('ref_type')) {
            $query->where('ref_type', $request->ref_type);
        }

        // Free-text search on the entity name or SKU (items and products).
        if ($request->filled('q')) {
            $q = $request->string('q')->toString();
            $query->where(function ($sub) use ($q) {
                $sub->whereHas('item', fn ($i) => $i->where('name', 'like', "%{$q}%")->orWhere('sku', 'like', "%{$q}%"))
                    ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$q}%")->orWhere('sku', 'like', "%{$q}%"));
            });
        }

        // `order_id` is the portal's internal Order id (sp_orders.id), which is
        // what the ledger stores in ref_id for ref_type = 'order'.
        if ($request->filled('order_id')) {
            $query->where('ref_type', 'order')->where('ref_id', $request->order_id);
        }

        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->to);
        }

        $rows = $query->paginate((int) $request->get('per_page', 50));

        // Resolve order references and creators in bulk (avoid N+1).
        $orderIds = $rows->getCollection()
            ->filter(fn ($m) => $m->ref_type === 'order' && $m->ref_id)
            ->pluck('ref_id')
            ->unique();

        $orders = Order::whereIn('id', $orderIds)
            ->get(['id', 'woocommerce_number', 'status'])
            ->keyBy('id');

        $userIds = $rows->getCollection()->pluck('created_by')->filter()->unique();

        $users = User::whereIn('id', $userIds)->get(['id', 'name'])->keyBy('id');

        $rows->getCollection()->transform(fn (ItemMovement $m) => $this->payload($m, $orders, $users));

        return response()->json($rows);
    }

    /** @param  Collection<int, Order>  $orders  @param  Collection<int, User>  $users */
    private function payload(ItemMovement $m, Collection $orders, Collection $users): array
    {
        $isItem = $m->item_id !== null;
        $entity = $isItem ? $m->item : $m->product;

        $refLabel = null;
        $refOrderStatus = null;

        if ($m->ref_type === 'order' && $m->ref_id) {
            $order = $orders->get($m->ref_id);

            if ($order) {
                $refLabel = '#'.$order->woocommerce_number;
                $refOrderStatus = $order->status;
            }
        } elseif ($m->ref_type === 'adjustment') {
            $refLabel = $m->reason ?: 'Manual adjustment';
        } elseif ($m->ref_type === 'seed') {
            $refLabel = $m->reason ?: 'Initial stock';
        }

        return [
            'id' => $m->id,
            'entity_type' => $isItem ? 'item' : 'product',
            'entity_id' => $isItem ? $m->item_id : $m->product_id,
            'entity_name' => $entity?->name ?? ($isItem ? 'Item #'.$m->item_id : 'Product #'.$m->product_id),
            'entity_sku' => $entity?->sku,
            'movement_type' => $m->movement_type,
            'qty_delta' => (float) $m->qty_delta,
            'reserved_delta' => (float) $m->reserved_delta,
            'on_hand_after' => (float) $m->on_hand_after,
            'reserved_after' => (float) $m->reserved_after,
            'ref_type' => $m->ref_type,
            'ref_id' => $m->ref_id,
            'ref_label' => $refLabel,
            'ref_order_status' => $refOrderStatus,
            'reason' => $m->reason,
            'created_by' => $users->get($m->created_by)?->name,
            'created_at' => $m->created_at,
        ];
    }
}
