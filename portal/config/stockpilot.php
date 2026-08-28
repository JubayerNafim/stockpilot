<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Webhook processing mode
    |--------------------------------------------------------------------------
    |
    | 'sync'  (default) — process order/product events immediately inside the
    |          webhook request. No queue worker needed; ideal for shared
    |          hosting. If processing fails the event is rolled back so the
    |          plugin retries cleanly.
    |
    | 'queue' — dispatch to the database queue and process with a worker
    |          (php artisan queue:work). Better for very high order volume.
    |
    */
    'webhook_mode' => env('SP_WEBHOOK_MODE', 'sync'),

];
