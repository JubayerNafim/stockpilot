<?php

namespace App\Http\Middleware;

use App\Models\StoreConnection;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates the WooCommerce plugin via its API key + HMAC signature.
 *
 * Headers: X-Api-Key, X-Timestamp (unix, ±300s), X-Nonce, X-Event-Id, X-Signature.
 * Signature = base64( HMAC-SHA256( secret, "{timestamp}:{nonce}:{event_id}:{body}" ) )
 */
class AuthenticateStoreKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->header('X-Api-Key');
        $timestamp = $request->header('X-Timestamp');
        $nonce = $request->header('X-Nonce');
        $eventId = $request->header('X-Event-Id');
        $signature = $request->header('X-Signature');

        if (! $apiKey || ! $timestamp || ! $nonce || ! $eventId || ! $signature) {
            return response()->json(['error' => 'Missing auth headers'], 400);
        }

        $store = StoreConnection::where('api_key_hash', hash('sha256', $apiKey))->first();

        if (! $store || ! $store->is_active || $store->revoked_at) {
            return response()->json(['error' => 'Invalid or revoked API key'], 401);
        }

        if (abs(time() - (int) $timestamp) > 300) {
            return response()->json(['error' => 'Timestamp outside allowed window'], 401);
        }

        $expected = base64_encode(
            hash_hmac('sha256', "{$timestamp}:{$nonce}:{$eventId}:{$request->getContent()}", (string) $store->api_secret, true),
        );

        if (! hash_equals($expected, (string) $signature)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $request->attributes->set('store', $store);

        return $next($request);
    }
}
