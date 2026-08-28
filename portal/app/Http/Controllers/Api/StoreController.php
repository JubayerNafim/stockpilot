<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StoreConnection;
use App\Models\WebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StoreController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $stores = StoreConnection::where('organization_id', $request->user()->organization_id)
            ->orderBy('name')
            ->get()
            ->map(fn (StoreConnection $store) => $this->payload($store));

        return response()->json(['stores' => $stores]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'store_url' => 'required|url',
        ]);

        $apiKey = 'sp_'.Str::random(32);
        $apiSecret = Str::random(64);

        $store = StoreConnection::create($data + [
            'organization_id' => $request->user()->organization_id,
            'api_key' => $apiKey,
            'api_secret' => $apiSecret,
        ]);

        return response()->json([
            'store' => $this->payload($store),
            'api_key' => $apiKey,
            'api_secret' => $apiSecret,
        ], 201);
    }

    public function update(Request $request, StoreConnection $store): JsonResponse
    {
        abort_if($store->organization_id !== $request->user()->organization_id, 404);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'store_url' => 'sometimes|url',
        ]);

        $store->fill($data)->save();

        return response()->json(['store' => $this->payload($store)]);
    }

    public function destroy(Request $request, StoreConnection $store): JsonResponse
    {
        abort_if($store->organization_id !== $request->user()->organization_id, 404);

        $store->update(['is_active' => false, 'revoked_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function rotateKey(Request $request, StoreConnection $store): JsonResponse
    {
        abort_if($store->organization_id !== $request->user()->organization_id, 404);

        $apiKey = 'sp_'.Str::random(32);
        $apiSecret = Str::random(64);

        $store->api_key = $apiKey;
        $store->api_secret = $apiSecret;
        $store->save();

        return response()->json([
            'api_key' => $apiKey,
            'api_secret' => $apiSecret,
        ]);
    }

    public function revoke(Request $request, StoreConnection $store): JsonResponse
    {
        abort_if($store->organization_id !== $request->user()->organization_id, 404);

        $store->update(['is_active' => false, 'revoked_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function health(Request $request, StoreConnection $store): JsonResponse
    {
        abort_if($store->organization_id !== $request->user()->organization_id, 404);

        $recent = WebhookEvent::where('store_id', $store->id)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['event_type', 'status', 'created_at']);

        return response()->json([
            'store' => $this->payload($store),
            'recent_events' => $recent,
        ]);
    }

    private function payload(StoreConnection $store): array
    {
        return [
            'id' => $store->id,
            'name' => $store->name,
            'store_url' => $store->store_url,
            'plugin_version' => $store->plugin_version,
            'is_active' => $store->is_active,
            'revoked_at' => $store->revoked_at,
            'last_seen_at' => $store->last_seen_at,
            'last_sync_at' => $store->last_sync_at,
            'last_error' => $store->last_error,
            'last_error_at' => $store->last_error_at,
            'sync_count' => $store->sync_count,
            'created_at' => $store->created_at,
        ];
    }
}
