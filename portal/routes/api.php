<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Api\MovementController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\SetupController;
use App\Http\Controllers\Api\StoreController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Setup (no auth) — self-install wizard
|--------------------------------------------------------------------------
*/
Route::prefix('setup')->group(function () {
    Route::get('status', [SetupController::class, 'status']);
    Route::post('database', [SetupController::class, 'configureDatabase']);
    Route::post('admin', [SetupController::class, 'createAdmin']);
});

/*
|--------------------------------------------------------------------------
| Auth
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('forgot', [AuthController::class, 'sendResetLink']);
    Route::get('me', [AuthController::class, 'me'])->middleware('auth:sanctum');
    Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
});

/*
|--------------------------------------------------------------------------
| Plugin-facing webhooks (Store API key + HMAC auth)
|--------------------------------------------------------------------------
*/
Route::middleware(['store.key'])->prefix('webhooks')->group(function () {
    Route::post('orders', [WebhookController::class, 'orders'])->middleware('throttle:120,1');
    Route::post('products', [WebhookController::class, 'products'])->middleware('throttle:120,1');
    Route::post('health', [WebhookController::class, 'health']);
    Route::get('test', [WebhookController::class, 'test']);
});

/*
|--------------------------------------------------------------------------
| Authenticated SPA API (cookie session via Sanctum)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum'])->group(function () {

    Route::get('dashboard', [DashboardController::class, 'index']);

    // Users — admin only
    Route::middleware(['role:admin'])->prefix('users')->group(function () {
        Route::get('/', [UserController::class, 'index']);
        Route::post('/', [UserController::class, 'store']);
        Route::get('{user}', [UserController::class, 'show']);
        Route::put('{user}', [UserController::class, 'update']);
        Route::delete('{user}', [UserController::class, 'destroy']);
        Route::patch('{user}/role', [UserController::class, 'role']);
        Route::patch('{user}/activate', [UserController::class, 'activate']);
        Route::patch('{user}/deactivate', [UserController::class, 'deactivate']);
    });

    // Items — manager can edit, staff can view/adjust
    Route::prefix('items')->group(function () {
        Route::get('/', [ItemController::class, 'index']);
        Route::get('low-stock', [ItemController::class, 'lowStock']);
        Route::get('{item}', [ItemController::class, 'show']);
        Route::get('{item}/movements', [ItemController::class, 'movements']);
        Route::get('{item}/value-history', [ItemController::class, 'valueHistory']);
        Route::post('/', [ItemController::class, 'store'])->middleware('role:manager,admin');
        Route::put('{item}', [ItemController::class, 'update'])->middleware('role:manager,admin');
        Route::delete('{item}', [ItemController::class, 'destroy'])->middleware('role:admin');
        Route::post('{item}/adjust', [ItemController::class, 'adjust']);
    });

    // Categories
    Route::prefix('categories')->group(function () {
        Route::get('/', [CategoryController::class, 'index']);
        Route::post('/', [CategoryController::class, 'store'])->middleware('role:manager,admin');
        Route::put('{category}', [CategoryController::class, 'update'])->middleware('role:manager,admin');
        Route::delete('{category}', [CategoryController::class, 'destroy'])->middleware('role:admin');
    });

    // Products (BOM)
    Route::prefix('products')->group(function () {
        Route::get('/', [ProductController::class, 'index']);
        Route::get('{product}', [ProductController::class, 'show']);
        Route::get('{product}/bom', [ProductController::class, 'bom']);
        Route::put('{product}/bom', [ProductController::class, 'saveBom'])->middleware('role:manager,admin');
        Route::get('{product}/cost-summary', [ProductController::class, 'costSummary']);
        Route::get('{product}/validate', [ProductController::class, 'validate']);
        Route::post('/', [ProductController::class, 'store'])->middleware('role:manager,admin');
        Route::put('{product}', [ProductController::class, 'update'])->middleware('role:manager,admin');
        Route::post('{product}/adjust', [ProductController::class, 'adjust'])->middleware('role:manager,admin');
        Route::delete('{product}', [ProductController::class, 'destroy'])->middleware('role:admin');
    });

    // Orders
    Route::prefix('orders')->group(function () {
        Route::post('bulk', [OrderController::class, 'bulk'])->middleware('role:manager,admin');
        Route::get('/', [OrderController::class, 'index']);
        Route::get('{order}', [OrderController::class, 'show']);
        Route::get('{order}/components', [OrderController::class, 'components']);
        Route::get('{order}/movements', [OrderController::class, 'movements']);
        Route::post('{order}/recompute', [OrderController::class, 'recompute']);
        Route::post('{order}/reprocess', [OrderController::class, 'reprocess'])->middleware('role:manager,admin');
        Route::delete('{order}', [OrderController::class, 'destroy'])->middleware('role:admin');
    });

    // Activity ledger — every movement for items & products
    Route::get('movements', [MovementController::class, 'index']);

    // Reports
    Route::prefix('reports')->group(function () {
        Route::get('asset-value', [ReportController::class, 'assetValue']);
        Route::get('consumption', [ReportController::class, 'consumption']);
        Route::get('orders', [ReportController::class, 'orders']);
        Route::get('low-stock', [ReportController::class, 'lowStock']);
        Route::get('unmapped-order-items', [ReportController::class, 'unmappedOrderItems']);
    });

    // Stores (connections) — manager+ can view; admin can manage
    Route::prefix('stores')->group(function () {
        Route::get('/', [StoreController::class, 'index'])->middleware('role:manager,admin');
        Route::post('/', [StoreController::class, 'store'])->middleware('role:admin');
        Route::put('{store}', [StoreController::class, 'update'])->middleware('role:admin');
        Route::delete('{store}', [StoreController::class, 'destroy'])->middleware('role:admin');
        Route::post('{store}/rotate-key', [StoreController::class, 'rotateKey'])->middleware('role:admin');
        Route::post('{store}/revoke', [StoreController::class, 'revoke'])->middleware('role:admin');
        Route::get('{store}/health', [StoreController::class, 'health'])->middleware('role:manager,admin');
    });

    // Settings
    Route::prefix('settings')->group(function () {
        Route::get('/', [SettingController::class, 'show'])->middleware('role:manager,admin');
        Route::put('/', [SettingController::class, 'update'])->middleware('role:manager,admin');
        Route::post('test-email', [SettingController::class, 'testEmail'])->middleware('role:manager,admin');
    });
});
