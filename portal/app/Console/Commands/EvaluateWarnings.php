<?php

namespace App\Console\Commands;

use App\Models\Item;
use App\Models\Organization;
use App\Models\Product;
use App\Services\LowStockNotifier;
use Illuminate\Console\Command;

class EvaluateWarnings extends Command
{
    protected $signature = 'warnings:evaluate';

    protected $description = 'Re-evaluate low-stock warnings for every item (scheduled hourly)';

    public function handle(LowStockNotifier $notifier): int
    {
        $count = 0;

        foreach (Organization::all() as $org) {
            foreach (Item::where('organization_id', $org->id)->get() as $item) {
                $notifier->evaluateItem($item->id, $org->id);
                $count++;
            }

            foreach (Product::where('organization_id', $org->id)->get() as $product) {
                $notifier->evaluateProduct($product->id, $org->id);
                $count++;
            }
        }

        $this->info("Evaluated {$count} item(s).");

        return self::SUCCESS;
    }
}
