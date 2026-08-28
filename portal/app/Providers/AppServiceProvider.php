<?php

namespace App\Providers;

use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Self-install: if .env has no APP_KEY, generate one so the setup
        // wizard can run without the user touching the command line.
        $this->ensureAppKey();
    }

    private function ensureAppKey(): void
    {
        $key = (string) config('app.key');

        $isValid = str_starts_with($key, 'base64:')
            && 32 === strlen(base64_decode(substr($key, 7), true) ?: '');

        if ($isValid) {
            return;
        }

        $newKey = 'base64:'.base64_encode(random_bytes(32));

        config(['app.key' => $newKey]);

        $path = file_exists(base_path('.env'))
            ? base_path('.env')
            : base_path('.env.example');

        if (file_exists($path)) {
            $contents = preg_replace('/^\s*APP_KEY=.*$/m', 'APP_KEY='.$newKey, (string) File::get($path), 1);
            File::put($path, $contents ?? '');
        }
    }
}
