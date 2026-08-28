<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Serve the single-build React SPA for every non-API GET request.
Route::get('/{any}', function (Request $request) {
    if ($request->is('api/*') || $request->is('sanctum/*')) {
        abort(404);
    }

    return view('app');
})->where('any', '.*');
