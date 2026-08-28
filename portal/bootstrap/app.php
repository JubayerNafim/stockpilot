<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Enable Sanctum cookie-based SPA auth on the api group.
        $middleware->statefulApi();

        // Self-install: migrate BEFORE Sanctum's session stack so a fresh
        // database (no tables yet) renders pages instead of 500ing on the
        // missing `sessions` table.
        $middleware->web(prepend: [
            \App\Http\Middleware\AutoMigrate::class,
        ]);

        // NOTE: do NOT add StartSession/EncryptCookies to the api group here.
        // Sanctum's EnsureFrontendRequestsAreStateful injects exactly one set
        // for stateful requests; adding a second set clobbers the session after
        // login (401 on every authenticated request from the SPA).
        $middleware->api(prepend: [
            \App\Http\Middleware\AutoMigrate::class,
        ]);

        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureRole::class,
            'store.key' => \App\Http\Middleware\AuthenticateStoreKey::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn ($request) => $request->is('api/*'),
        );
    })->create();
