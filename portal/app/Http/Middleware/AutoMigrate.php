<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * Self-install guard: if the configured database is reachable but the schema
 * is missing, run the migrations automatically. This MUST run before the
 * session middleware (which reads the `sessions` table), otherwise a fresh
 * database 500s on every request before anything can create the tables.
 *
 * It also applies newly shipped migrations to EXISTING installs — otherwise a
 * deploy that adds columns (e.g. product cost/stock) silently 500s on every
 * query referencing them, because the `sp_organizations` guard alone never
 * triggers on an already-set-up database.
 */
class AutoMigrate
{
    public function handle(Request $request, Closure $next): Response
    {
        $this->migrateIfNeeded();

        return $next($request);
    }

    private function migrateIfNeeded(): void
    {
        try {
            if (! Schema::hasTable('sp_organizations')) {
                // Fresh install — run everything.
                Artisan::call('migrate', ['--force' => true]);

                return;
            }

            // Existing install — apply only migrations shipped after setup so a
            // deploy that adds columns never 500s on them. The migrator scans
            // disk + the `migrations` table, which is a cheap no-op when up to date.
            $migrator = app('migrator');
            $pending = array_diff_key(
                $migrator->getMigrationFiles(app()->databasePath('migrations')),
                array_flip($migrator->getRepository()->getRan()),
            );

            if ($pending !== []) {
                Artisan::call('migrate', ['--force' => true]);
            }
        } catch (\Throwable $e) {
            // DB unreachable / not configured yet — the setup wizard handles this.
            report($e);
        }
    }
}
