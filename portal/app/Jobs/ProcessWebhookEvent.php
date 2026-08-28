<?php

namespace App\Jobs;

use App\Models\WebhookEvent;
use App\Services\InventoryEngine;
use App\Services\ProductSyncProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ProcessWebhookEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 60, 120, 300];

    public function __construct(public int $webhookEventId)
    {
    }

    public function handle(): void
    {
        DB::transaction(function (): void {
            $event = WebhookEvent::lockForUpdate()->find($this->webhookEventId);

            if (! $event || in_array($event->status, ['processed', 'duplicate', 'noop'], true)) {
                return;
            }

            $event->status = 'processing';
            $event->save();

            $store = $event->store()->lockForUpdate()->first();

            if (! $store) {
                $event->status = 'failed';
                $event->processing_error = 'Store connection not found';
                $event->save();

                return;
            }

            $body = json_decode((string) $event->payload, true) ?? [];

            if ($event->event_type === 'order.sync') {
                $result = app(InventoryEngine::class)->processOrderEvent(
                    $body['order'] ?? $body,
                    $store,
                    $event,
                );

                $event->status = $result['action'] === 'noop' ? 'noop' : 'processed';
            } elseif (in_array($event->event_type, ['product.sync', 'product.delete'], true)) {
                app(ProductSyncProcessor::class)->syncProduct(
                    $body['product'] ?? $body,
                    $store,
                    $event->event_type === 'product.delete',
                );

                $event->status = 'processed';
            } else {
                $event->status = 'noop';
            }

            $store->last_sync_at = now();
            $store->sync_count = ($store->sync_count ?? 0) + 1;
            $store->last_error = null;
            $store->save();

            $event->processed_at = now();
            $event->save();
        });
    }
}
