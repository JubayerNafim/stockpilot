<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\StoreConnection;
use App\Models\WebhookEvent;
use App\Services\InventoryEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Feeds fixture order-status events through the inventory engine so the
 * reserve/confirm/release/return flow can be verified without WooCommerce.
 *
 * File format (JSON): an array of events, each { "order": {...} }.
 *   php artisan orders:simulate tests/fixtures/orders.json
 */
class SimulateOrders extends Command
{
    protected $signature = 'orders:simulate {file? : Path to a JSON fixture of order events}';

    protected $description = 'Simulate WooCommerce order status events through the inventory engine';

    public function handle(): int
    {
        $file = $this->argument('file') ?? base_path('tests/fixtures/orders.json');

        if (! is_file($file)) {
            $this->error("Fixture not found: {$file}");

            return self::FAILURE;
        }

        $events = json_decode((string) file_get_contents($file), true);

        if (! is_array($events) || empty($events)) {
            $this->error('Fixture must be a non-empty JSON array of events.');

            return self::FAILURE;
        }

        $org = Organization::firstOrCreate(['name' => 'Demo Company']);
        $store = StoreConnection::firstOrCreate(
            ['organization_id' => $org->id, 'store_url' => 'https://demo.test'],
            [
                'name' => 'Demo Store',
                'api_key' => 'sp_'.Str::random(32),
                'api_secret' => Str::random(64),
            ],
        );

        foreach ($events as $index => $event) {
            $orderData = $event['order'] ?? $event;
            $status = strtolower((string) ($orderData['status'] ?? 'processing'));

            $webhookEvent = WebhookEvent::create([
                'organization_id' => $org->id,
                'store_id' => $store->id,
                'woocommerce_order_id' => $orderData['id'] ?? null,
                'event_id' => 'sim-'.$index.'-'.Str::random(8),
                'nonce' => Str::random(32),
                'event_type' => 'order.sync',
                'wc_status' => $status,
                'payload' => json_encode($event),
                'status' => 'received',
            ]);

            $result = app(InventoryEngine::class)->processOrderEvent($orderData, $store, $webhookEvent);

            $this->line(sprintf(
                '  [%d] order %s status=%-22s action=%-10s reservation_state=%s',
                $index + 1,
                $orderData['id'] ?? '?',
                $status,
                $result['action'],
                $result['reservation_state'],
            ));
        }

        $this->info('Simulation complete.');

        return self::SUCCESS;
    }
}
