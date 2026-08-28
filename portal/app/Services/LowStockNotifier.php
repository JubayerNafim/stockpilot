<?php

namespace App\Services;

use App\Mail\LowStockAlert;
use App\Models\Item;
use App\Models\Product;
use App\Models\StockWarning;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Creates low-stock warning rows and emails the configured address.
 * One email per threshold crossing; a cooldown suppresses re-sends while
 * stock oscillates around the threshold. Warnings clear when stock recovers.
 * Works for both items (components) and products (finished goods).
 */
class LowStockNotifier
{
    public function __construct(private SettingService $settings)
    {
    }

    public function evaluateForItems(array $itemIds, int $organizationId): void
    {
        foreach (array_values(array_unique(array_filter($itemIds))) as $itemId) {
            $this->evaluateItem((int) $itemId, $organizationId);
        }
    }

    public function evaluateForProducts(array $productIds, int $organizationId): void
    {
        foreach (array_values(array_unique(array_filter($productIds))) as $productId) {
            $this->evaluateProduct((int) $productId, $organizationId);
        }
    }

    public function evaluateItem(int $itemId, int $organizationId): void
    {
        $item = Item::where('organization_id', $organizationId)->find($itemId);

        if (! $item) {
            return;
        }

        $active = StockWarning::where('item_id', $item->id)
            ->where('status', 'active')
            ->first();

        $this->applyWarning(
            $organizationId,
            $item->isLowStock(),
            $active,
            fn () => StockWarning::create([
                'organization_id' => $organizationId,
                'item_id' => $item->id,
                'warning_type' => 'low_stock',
                'status' => 'active',
                'triggered_at' => now(),
                'cooldown_until' => now()->addHours($this->cooldownHours($organizationId)),
            ]),
        );
    }

    public function evaluateProduct(int $productId, int $organizationId): void
    {
        $product = Product::where('organization_id', $organizationId)->find($productId);

        if (! $product) {
            return;
        }

        $active = StockWarning::where('product_id', $product->id)
            ->where('status', 'active')
            ->first();

        $this->applyWarning(
            $organizationId,
            $product->isLowStock(),
            $active,
            fn () => StockWarning::create([
                'organization_id' => $organizationId,
                'product_id' => $product->id,
                'warning_type' => 'low_stock',
                'status' => 'active',
                'triggered_at' => now(),
                'cooldown_until' => now()->addHours($this->cooldownHours($organizationId)),
            ]),
        );
    }

    private function applyWarning(int $organizationId, bool $isLow, ?StockWarning $active, \Closure $create): void
    {
        if ($isLow) {
            if ($active) {
                // Already warned for this crossing. If that email never went out
                // (a previous attempt failed) and the cooldown has lapsed, retry
                // it — otherwise a single transient failure would lose the alert
                // forever because the active warning suppresses re-sends.
                if ($active->email_sent || ($active->cooldown_until && $active->cooldown_until->isFuture())) {
                    return;
                }

                $this->sendEmail($active);

                return;
            }

            $this->sendEmail($create());
        } elseif ($active) {
            // Stock recovered — clear the warning.
            $active->update(['status' => 'cleared', 'cleared_at' => now()]);
        }
    }

    private function cooldownHours(int $organizationId): int
    {
        return (int) ($this->settings->get($organizationId, 'warning_cooldown_hours') ?? 24);
    }

    private function sendEmail(StockWarning $warning): bool
    {
        $email = $warning->organization?->low_stock_email;

        if (! $email) {
            return false;
        }

        try {
            // Send synchronously — shared hosting has no queue worker, so a
            // queued mailable would sit in the jobs table and never deliver.
            Mail::to($email)->send(new LowStockAlert($warning));
            $warning->update(['email_sent' => true, 'email_sent_at' => now()]);

            return true;
        } catch (\Throwable $e) {
            // Don't lose the failure: log enough context to debug, and leave
            // email_sent=false so the next evaluation retries the send.
            Log::error('Low-stock alert email failed', [
                'warning_id' => $warning->id,
                'entity' => $warning->entityLabel(),
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
            report($e);

            return false;
        }
    }
}
