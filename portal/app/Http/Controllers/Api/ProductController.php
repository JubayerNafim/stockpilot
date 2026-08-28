<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BomLine;
use App\Models\Item;
use App\Models\ItemMovement;
use App\Models\Product;
use App\Services\LowStockNotifier;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    public function __construct(private LowStockNotifier $notifier)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $orgId = $request->user()->organization_id;

        $query = Product::where('organization_id', $orgId)
            ->with('store')
            ->withCount('bomLines')
            ->orderBy('name');

        if ($request->filled('search')) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$request->search}%")
                ->orWhere('sku', 'like', "%{$request->search}%"));
        }

        if ($request->filled('source')) {
            $query->where('source', $request->source);
        }

        if ($request->filled('store_id')) {
            $query->where('store_id', $request->store_id);
        }

        if ($request->boolean('no_recipe')) {
            $query->doesntHave('bomLines');
        }

        $products = $query->paginate((int) $request->get('per_page', 25));

        $products->getCollection()->transform(fn (Product $p) => $this->payload($p));

        return response()->json($products);
    }

    public function show(Request $request, Product $product): JsonResponse
    {
        $this->assertSameOrg($request, $product);

        return response()->json(['product' => $this->payload($product)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'sku' => 'nullable|string|max:255',
            'price' => 'nullable|numeric|min:0',
            'cost' => 'nullable|numeric|min:0',
            'quantity_on_hand' => 'nullable|numeric|min:0',
            'low_stock_threshold' => 'nullable|numeric|min:0',
        ]);

        $product = Product::create($data + [
            'organization_id' => $request->user()->organization_id,
            'source' => 'manual',
        ]);

        $this->notifier->evaluateProduct($product->id, $request->user()->organization_id);

        return response()->json(['product' => $this->payload($product)], 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $this->assertSameOrg($request, $product);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'sku' => 'sometimes|nullable|string|max:255',
            'price' => 'sometimes|numeric|min:0',
            'cost' => 'sometimes|nullable|numeric|min:0',
            // Negative on-hand is allowed here so a product driven below zero by
            // orders can still have its cost/threshold edited.
            'quantity_on_hand' => 'sometimes|numeric',
            'low_stock_threshold' => 'sometimes|numeric|min:0',
            'is_active' => 'sometimes|boolean',
        ]);

        $product->fill($data)->save();
        $this->notifier->evaluateProduct($product->id, $request->user()->organization_id);

        return response()->json(['product' => $this->payload($product)]);
    }

    /** Adjust a product's finished-goods stock (add/remove) with a ledger row. */
    public function adjust(Request $request, Product $product): JsonResponse
    {
        $this->assertSameOrg($request, $product);

        $data = $request->validate([
            'type' => 'required|in:add,remove',
            'quantity' => 'required|numeric|min:0.0001',
            'reason' => 'nullable|string|max:500',
        ]);

        $allowNegative = (bool) app(SettingService::class)->get($request->user()->organization_id, 'allow_negative_stock', false);
        $delta = $data['type'] === 'add' ? (float) $data['quantity'] : -((float) $data['quantity']);
        $newQty = round($product->quantity_on_hand + $delta, 4);

        // Only a "remove" that would take the balance negative is an error.
        // Adding stock back from an already-negative balance must always be
        // allowed — otherwise a single +1 can never recover.
        if ($data['type'] === 'remove' && $newQty < 0 && ! $allowNegative) {
            return response()->json(['error' => 'Cannot remove more stock than is on hand.'], 422);
        }

        DB::transaction(function () use ($product, $newQty, $delta, $request, $data) {
            $product->quantity_on_hand = $newQty;
            $product->save();

            ItemMovement::create([
                'organization_id' => $request->user()->organization_id,
                'product_id' => $product->id,
                'movement_type' => 'adjustment',
                'qty_delta' => $delta,
                'reserved_delta' => 0,
                'on_hand_after' => $newQty,
                'reserved_after' => $product->quantity_reserved,
                'ref_type' => 'adjustment',
                'ref_id' => $product->id,
                'cost_price_at_movement' => $product->cost ?? 0,
                'reason' => $data['reason'] ?? 'Manual adjustment',
                'created_by' => $request->user()->id,
            ]);
        });

        $this->notifier->evaluateProduct($product->id, $request->user()->organization_id);

        return response()->json(['product' => $this->payload($product)]);
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->assertSameOrg($request, $product);

        $product->delete();

        return response()->json(['ok' => true]);
    }

    public function bom(Request $request, Product $product): JsonResponse
    {
        $this->assertSameOrg($request, $product);

        $lines = $product->bomLines()->with('item.category', 'componentProduct')->get()->map(fn (BomLine $line) => [
            'id' => $line->id,
            'component_type' => $line->component_type,
            'item_id' => $line->item_id,
            'component_product_id' => $line->component_product_id,
            'item_name' => $line->item?->name,
            'item_sku' => $line->item?->sku,
            'item_unit' => $line->item?->unit,
            'item_cost' => $line->item?->cost_price,
            'item_available' => round(($line->item?->quantity_on_hand ?? 0) - ($line->item?->quantity_reserved ?? 0), 4),
            'product_name' => $line->componentProduct?->name,
            'product_sku' => $line->componentProduct?->sku,
            'product_cost' => $line->componentProduct?->cost !== null ? (float) $line->componentProduct->cost : null,
            'product_available' => $line->componentProduct ? round($line->componentProduct->available(), 4) : null,
            'quantity' => (float) $line->quantity,
            'line_cost' => round($line->quantity * (
                $line->component_type === 'product'
                    ? ($line->componentProduct?->cost ?? 0)
                    : ($line->item?->cost_price ?? 0)
            ), 4),
        ]);

        return response()->json(['bom' => $lines]);
    }

    public function saveBom(Request $request, Product $product): JsonResponse
    {
        $this->assertSameOrg($request, $product);

        $data = $request->validate([
            'lines' => 'present|array', // an empty recipe is allowed (clears the BOM)
            'lines.*.type' => 'required|in:item,product',
            'lines.*.id' => 'required|integer|min:1',
            'lines.*.quantity' => 'required|numeric|gt:0',
        ]);

        // Resolve each component and reject self-reference / circular recipes
        // before touching the existing recipe.
        $components = [];

        foreach ($data['lines'] as $i => $line) {
            $id = (int) $line['id'];

            if ($line['type'] === 'product') {
                $component = Product::where('organization_id', $request->user()->organization_id)->whereKey($id)->first();
            } else {
                $component = Item::where('organization_id', $request->user()->organization_id)->whereKey($id)->first();
            }

            if (! $component) {
                return response()->json(['error' => 'Line #'.($i + 1).' references a '.$line['type'].' that does not exist.'], 422);
            }

            if ($line['type'] === 'product') {
                if ($id === $product->id) {
                    return response()->json(['error' => 'A product cannot be a component of itself.'], 422);
                }

                if ($this->wouldCreateCycle($product->id, $id, $request->user()->organization_id, $data['lines'])) {
                    return response()->json(['error' => 'This recipe would create a circular reference.'], 422);
                }
            }

            $components[] = ['type' => $line['type'], 'id' => $id, 'quantity' => (float) $line['quantity']];
        }

        $product->bomLines()->delete();

        foreach ($components as $component) {
            BomLine::create([
                'organization_id' => $request->user()->organization_id,
                'product_id' => $product->id,
                'item_id' => $component['type'] === 'item' ? $component['id'] : null,
                'component_product_id' => $component['type'] === 'product' ? $component['id'] : null,
                'component_type' => $component['type'],
                'quantity' => $component['quantity'],
            ]);
        }

        return response()->json(['ok' => true, 'bom' => $this->bom($request, $product)->getData()->bom]);
    }

    /**
     * Does adding parent→child (plus the other proposed product lines) create a
     * path from child back to parent? Walks existing recipes of all products too.
     */
    private function wouldCreateCycle(int $parentId, int $childId, int $orgId, array $proposedLines): bool
    {
        $children = [];

        // Existing product-component edges.
        BomLine::where('organization_id', $orgId)
            ->where('component_type', 'product')
            ->get(['product_id', 'component_product_id'])
            ->each(fn (BomLine $line) => $children[(int) $line->product_id][] = (int) $line->component_product_id);

        // Proposed edges from the edited product.
        foreach ($proposedLines as $line) {
            if ($line['type'] === 'product') {
                $children[$parentId][] = (int) $line['id'];
            }
        }

        $visited = [];
        $stack = [$childId];

        while ($stack) {
            $current = array_pop($stack);

            if (isset($visited[$current])) {
                continue;
            }

            $visited[$current] = true;

            if ($current === $parentId) {
                return true;
            }

            foreach (($children[$current] ?? []) as $c) {
                if (! isset($visited[$c])) {
                    $stack[] = $c;
                }
            }
        }

        return false;
    }

    public function costSummary(Request $request, Product $product): JsonResponse
    {
        $this->assertSameOrg($request, $product);

        return response()->json([
            'material_cost' => $product->materialCost(),
            'price' => (float) $product->price,
            'margin' => round($product->price - $product->materialCost(), 4),
            'has_recipe' => $product->hasRecipe(),
        ]);
    }

    public function validate(Request $request, Product $product): JsonResponse
    {
        $this->assertSameOrg($request, $product);

        $results = $product->bomLines()->with('item', 'componentProduct')->get()->map(function (BomLine $line) {
            $available = $line->component_type === 'product'
                ? ($line->componentProduct?->available() ?? 0)
                : round(($line->item?->quantity_on_hand ?? 0) - ($line->item?->quantity_reserved ?? 0), 4);
            $required = (float) $line->quantity;

            return [
                'component_type' => $line->component_type,
                'component_id' => $line->component_type === 'product' ? $line->component_product_id : $line->item_id,
                'component_name' => $line->component_type === 'product' ? $line->componentProduct?->name : $line->item?->name,
                'required' => $required,
                'available' => round((float) $available, 4),
                'sufficient' => $available >= $required,
            ];
        });

        return response()->json([
            'recipe_ready' => $product->hasRecipe(),
            'all_stocked' => $results->every(fn ($r) => $r['sufficient']),
            'lines' => $results,
        ]);
    }

    private function assertSameOrg(Request $request, Product $product): void
    {
        abort_if($product->organization_id !== $request->user()->organization_id, 404);
    }

    private function payload(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'source' => $product->source,
            'store_id' => $product->store_id,
            'store_name' => $product->store?->name,
            'woo_product_id' => $product->woo_product_id,
            'product_type' => $product->product_type,
            'publish_status' => $product->publish_status,
            'price' => (float) $product->price,
            'cost' => $product->cost !== null ? (float) $product->cost : null,
            'quantity_on_hand' => (float) $product->quantity_on_hand,
            'quantity_reserved' => (float) $product->quantity_reserved,
            'available' => $product->available(),
            'low_stock_threshold' => (float) $product->low_stock_threshold,
            'is_low_stock' => $product->isLowStock(),
            'stock_value' => $product->stockValue(),
            'regular_price' => $product->regular_price !== null ? (float) $product->regular_price : null,
            'sale_price' => $product->sale_price !== null ? (float) $product->sale_price : null,
            'stock_status' => $product->stock_status,
            'stock_quantity' => $product->stock_quantity !== null ? (float) $product->stock_quantity : null,
            'categories' => $product->categories ?? [],
            'image_url' => $product->image_url,
            'has_recipe' => $product->hasRecipe(),
            'material_cost' => $product->materialCost(),
            'is_active' => $product->is_active,
            'synced_at' => $product->synced_at,
            'created_at' => $product->created_at,
        ];
    }
}
