<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AssetSnapshot;
use App\Models\Item;
use App\Models\ItemMovement;
use App\Models\StockAdjustment;
use App\Services\AssetValuation;
use App\Services\LowStockNotifier;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ItemController extends Controller
{
    public function __construct(private AssetValuation $valuation, private LowStockNotifier $notifier)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $orgId = $request->user()->organization_id;

        $query = Item::where('organization_id', $orgId)
            ->with('category')
            ->orderBy('name');

        if ($request->filled('search')) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$request->search}%")
                ->orWhere('sku', 'like', "%{$request->search}%"));
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->boolean('low_stock_only')) {
            $query->whereColumn('quantity_on_hand', '<=', 'low_stock_threshold')
                ->where('low_stock_threshold', '>', 0);
        }

        $items = $query->paginate((int) $request->get('per_page', 25));

        $items->getCollection()->transform(fn (Item $item) => $this->payload($item));

        return response()->json($items);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'sku' => 'nullable|string|max:255',
            'category_id' => 'nullable|exists:sp_categories,id',
            'unit' => 'required|string|max:32',
            'cost_price' => 'required|numeric|min:0',
            'quantity_on_hand' => 'required|numeric|min:0',
            'low_stock_threshold' => 'nullable|numeric|min:0',
        ]);

        $orgId = $request->user()->organization_id;

        $item = DB::transaction(function () use ($data, $orgId) {
            $item = Item::create($data + ['organization_id' => $orgId]);

            if ((float) $data['quantity_on_hand'] > 0) {
                ItemMovement::create([
                    'organization_id' => $orgId,
                    'item_id' => $item->id,
                    'movement_type' => 'initial',
                    'qty_delta' => (float) $data['quantity_on_hand'],
                    'reserved_delta' => 0,
                    'on_hand_after' => (float) $data['quantity_on_hand'],
                    'reserved_after' => 0,
                    'ref_type' => 'seed',
                    'cost_price_at_movement' => (float) $data['cost_price'],
                    'reason' => 'Initial stock on creation',
                    'created_by' => auth()->id(),
                ]);
            }

            return $item;
        });

        return response()->json(['item' => $this->payload($item)], 201);
    }

    public function show(Request $request, Item $item): JsonResponse
    {
        $this->assertSameOrg($request, $item);

        return response()->json(['item' => $this->payload($item)]);
    }

    public function update(Request $request, Item $item): JsonResponse
    {
        $this->assertSameOrg($request, $item);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'sku' => 'sometimes|nullable|string|max:255',
            'category_id' => 'sometimes|nullable|exists:sp_categories,id',
            'unit' => 'sometimes|string|max:32',
            'cost_price' => 'sometimes|numeric|min:0',
            'low_stock_threshold' => 'sometimes|nullable|numeric|min:0',
        ]);

        $item->fill($data)->save();
        $this->notifier->evaluateItem($item->id, $request->user()->organization_id);

        return response()->json(['item' => $this->payload($item)]);
    }

    public function destroy(Request $request, Item $item): JsonResponse
    {
        $this->assertSameOrg($request, $item);

        $item->delete();

        return response()->json(['ok' => true]);
    }

    public function adjust(Request $request, Item $item): JsonResponse
    {
        $this->assertSameOrg($request, $item);

        $data = $request->validate([
            'type' => 'required|in:add,remove',
            'quantity' => 'required|numeric|min:0.0001',
            'reason' => 'nullable|string|max:500',
        ]);

        $orgId = $request->user()->organization_id;
        $allowNegative = (bool) app(SettingService::class)->get($orgId, 'allow_negative_stock', false);
        $delta = $data['type'] === 'add' ? (float) $data['quantity'] : -((float) $data['quantity']);

        $item = DB::transaction(function () use ($item, $delta, $data, $orgId, $allowNegative) {
            $item = Item::whereKey($item->id)->lockForUpdate()->first();

            $newOnHand = round($item->quantity_on_hand + $delta, 4);

            // Only a "remove" that would take the balance negative is an error.
            // Adding stock back from an already-negative balance must always be
            // allowed — otherwise a single +1 can never recover.
            if ($data['type'] === 'remove' && $newOnHand < 0 && ! $allowNegative) {
                abort(422, 'Cannot remove more stock than is on hand.');
            }

            $item->quantity_on_hand = $newOnHand;
            $item->save();

            StockAdjustment::create([
                'organization_id' => $orgId,
                'item_id' => $item->id,
                'adjustment_type' => $data['type'],
                'quantity' => (float) $data['quantity'],
                'reason' => $data['reason'] ?? null,
                'created_by' => auth()->id(),
            ]);

            ItemMovement::create([
                'organization_id' => $orgId,
                'item_id' => $item->id,
                'movement_type' => 'adjustment',
                'qty_delta' => $delta,
                'reserved_delta' => 0,
                'on_hand_after' => $newOnHand,
                'reserved_after' => $item->quantity_reserved,
                'ref_type' => 'adjustment',
                'ref_id' => $item->id,
                'cost_price_at_movement' => $item->cost_price,
                'reason' => ($data['reason'] ?? null) ?: ($data['type'] === 'add' ? 'Stock added' : 'Stock removed'),
                'created_by' => auth()->id(),
            ]);

            return $item;
        });

        $this->notifier->evaluateItem($item->id, $orgId);

        return response()->json(['item' => $this->payload($item)]);
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
            ->map(fn (Item $item) => $this->payload($item));

        return response()->json(['items' => $items]);
    }

    public function movements(Request $request, Item $item): JsonResponse
    {
        $this->assertSameOrg($request, $item);

        $movements = ItemMovement::where('item_id', $item->id)
            ->orderByDesc('created_at')
            ->paginate((int) $request->get('per_page', 50));

        return response()->json($movements);
    }

    public function valueHistory(Request $request, Item $item): JsonResponse
    {
        $this->assertSameOrg($request, $item);

        $snapshots = AssetSnapshot::where('organization_id', $request->user()->organization_id)
            ->orderByDesc('captured_at')
            ->limit(90)
            ->get()
            ->map(fn ($s) => ['captured_at' => $s->captured_at, 'total_value' => $s->total_value]);

        return response()->json(['snapshots' => $snapshots]);
    }

    public function assertSameOrg(Request $request, Item $item): void
    {
        abort_if($item->organization_id !== $request->user()->organization_id, 404);
    }

    private function payload(Item $item): array
    {
        return [
            'id' => $item->id,
            'name' => $item->name,
            'sku' => $item->sku,
            'unit' => $item->unit,
            'category_id' => $item->category_id,
            'category_name' => $item->category?->name,
            'cost_price' => (float) $item->cost_price,
            'quantity_on_hand' => (float) $item->quantity_on_hand,
            'quantity_reserved' => (float) $item->quantity_reserved,
            'available' => round($item->quantity_on_hand - $item->quantity_reserved, 4),
            'low_stock_threshold' => (float) $item->low_stock_threshold,
            'is_low_stock' => $item->isLowStock(),
            'asset_value' => round($item->quantity_on_hand * $item->cost_price, 4),
            'is_active' => $item->is_active,
            'created_at' => $item->created_at,
        ];
    }
}
