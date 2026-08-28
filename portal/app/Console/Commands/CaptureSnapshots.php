<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\AssetValuation;
use Illuminate\Console\Command;

class CaptureSnapshots extends Command
{
    protected $signature = 'snapshots:capture';

    protected $description = 'Capture nightly asset-value snapshots for time-series reporting';

    public function handle(AssetValuation $valuation): int
    {
        $count = 0;

        foreach (Organization::all() as $org) {
            $snapshot = $valuation->captureSnapshot($org->id);
            $count++;
            $this->line(sprintf('  Captured %s (value %s)', $org->name, number_format($snapshot->total_value, 2)));
        }

        $this->info("Captured {$count} snapshot(s).");

        return self::SUCCESS;
    }
}
