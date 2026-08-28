<?php

namespace App\Console\Commands;

use App\Mail\StockSummary;
use App\Models\Item;
use App\Models\Organization;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendStockSummary extends Command
{
    protected $signature = 'stock:summary';

    protected $description = 'Email each organization a daily stock summary — a warning when anything is low, otherwise a full stock report';

    public function handle(): int
    {
        $sent = 0;
        $skipped = 0;
        $failed = 0;

        foreach (Organization::all() as $org) {
            $email = $org->low_stock_email;

            if (! $email) {
                $this->warn("Org '{$org->name}' has no low-stock email set — skipping.");
                $skipped++;

                continue;
            }

            $items = Item::where('organization_id', $org->id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get();

            $products = Product::where('organization_id', $org->id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get();

            $summary = new StockSummary($org, $items, $products);

            try {
                Mail::to($email)->send($summary);
                $sent++;
                $this->info(
                    ($summary->hasLowStock() ? 'Sent low-stock WARNING' : 'Sent stock REPORT')
                    ." to '{$org->name}' ({$items->count()} component(s), {$products->count()} product(s))."
                );
            } catch (\Throwable $e) {
                $failed++;
                $this->error("Failed to send summary to '{$org->name}': {$e->getMessage()}");
                report($e);
            }
        }

        $this->info(
            "Stock summary complete: {$sent} sent, {$skipped} skipped (no email), {$failed} failed."
        );

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
