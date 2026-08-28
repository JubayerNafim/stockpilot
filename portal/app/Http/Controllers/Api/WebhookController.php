<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWebhookEvent;
use App\Models\WebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function orders(Request $request): JsonResponse
    {
        $store = $request->attributes->get('store');
        $body = json_decode($request->getContent(), true);

        if (! is_array($body) || empty($body['order']['id'])) {
            return response()->json(['error' => 'Invalid order payload'], 422);
        }

        return $this->ingest($request, $store, 'order.sync', $body, $body['order']['id'] ?? null, $body['order']['status'] ?? null);
    }

    public function products(Request $request): JsonResponse
    {
        $store = $request->attributes->get('store');
        $body = json_decode($request->getContent(), true);

        if (! is_array($body) || empty($body['product']['id'])) {
            return response()->json(['error' => 'Invalid product payload'], 422);
        }

        $type = ! empty($body['delete']) ? 'product.delete' : 'product.sync';

        return $this->ingest($request, $store, $type, $body, null, null);
    }

    public function health(Request $request): JsonResponse
    {
        $store = $request->attributes->get('store');
        $store->last_seen_at = now();
        $store->save();

        return response()->json(['ok' => true, 'store_id' => $store->id]);
    }

    public function test(Request $request): JsonResponse
    {
        $store = $request->attributes->get('store');
        $store->last_seen_at = now();
        $store->save();

        return response()->json([
            'ok' => true,
            'server_time' => now()->toIso8601String(),
            'store_id' => $store->id,
            'plugin_version' => $store->plugin_version,
        ]);
    }

    private function dispatch(WebhookEvent $event): void
    {
        if (config('stockpilot.webhook_mode') === 'queue') {
            ProcessWebhookEvent::dispatch($event->id);

            return;
        }

        // Sync mode (shared hosting): process now. On failure roll the event back
        // so the plugin's retry re-inserts and re-processes cleanly.
        try {
            ProcessWebhookEvent::dispatchSync($event->id);
        } catch (\Throwable $e) {
            $event->delete();
            report($e);

            abort(500, 'Event processing failed');
        }
    }

    private function ingest(Request $request, $store, string $type, array $body, ?int $orderId, ?string $status): JsonResponse
    {
        $store->last_seen_at = now();
        $store->save();

        try {
            $event = WebhookEvent::create([
                'organization_id' => $store->organization_id,
                'store_id' => $store->id,
                'woocommerce_order_id' => $orderId,
                'event_id' => (string) $request->header('X-Event-Id'),
                'nonce' => (string) $request->header('X-Nonce'),
                'signature' => (string) $request->header('X-Signature'),
                'event_type' => $type,
                'wc_status' => $status ? strtolower($status) : null,
                'payload' => json_encode($body),
                'status' => 'received',
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['ok' => true, 'duplicate' => true], 409);
        }

        $this->dispatch($event);

        return response()->json(['ok' => true, 'event_id' => $event->id]);
    }
}
