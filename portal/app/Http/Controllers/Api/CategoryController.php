<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $categories = Category::where('organization_id', $request->user()->organization_id)
            ->withCount('items')
            ->orderBy('name')
            ->get();

        return response()->json(['categories' => $categories]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'parent_id' => 'nullable|exists:sp_categories,id',
        ]);

        $category = Category::create($data + ['organization_id' => $request->user()->organization_id]);

        return response()->json(['category' => $category], 201);
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        abort_if($category->organization_id !== $request->user()->organization_id, 404);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'parent_id' => 'sometimes|nullable|exists:sp_categories,id',
        ]);

        $category->fill($data)->save();

        return response()->json(['category' => $category]);
    }

    public function destroy(Request $request, Category $category): JsonResponse
    {
        abort_if($category->organization_id !== $request->user()->organization_id, 404);

        if ($category->items()->exists() || $category->children()->exists()) {
            return response()->json(['error' => 'Category has items or subcategories.'], 422);
        }

        $category->delete();

        return response()->json(['ok' => true]);
    }
}
