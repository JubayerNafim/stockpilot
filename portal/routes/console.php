<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Nightly asset-value snapshot
Schedule::command('snapshots:capture')->dailyAt('00:05');

// Hourly low-stock warning sweep (catches threshold changes made in the portal)
Schedule::command('warnings:evaluate')->hourly();

// Keep the DB queue drained even if no worker is running.
Schedule::command('queue:work --once --stop-when-empty')->everyMinute()->withoutOverlapping();
