<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SetupService
{
    /** Has the app been installed (tables + admin account)? */
    public function isSetup(): bool
    {
        try {
            return Schema::hasTable('sp_organizations')
                && Schema::hasTable('sp_users')
                && User::exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /** Test a MySQL connection with the given credentials. */
    public function testMySqlConnection(string $host, int|string $port, string $database, string $username, ?string $password): bool
    {
        try {
            new \PDO(
                "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
                $username,
                $password,
                [\PDO::ATTR_TIMEOUT => 5],
            );

            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    /**
     * Point the app at MySQL, write .env, and run migrations in-process.
     * Returns false when the DB is unreachable.
     */
    public function configureDatabase(string $host, int|string $port, string $database, string $username, ?string $password): bool
    {
        if (! $this->testMySqlConnection($host, $port, $database, $username, $password)) {
            return false;
        }

        $this->writeEnvDatabase($host, $port, $database, $username, $password);

        config([
            'database.default' => 'mysql',
            'database.connections.mysql.host' => $host,
            'database.connections.mysql.port' => (int) $port,
            'database.connections.mysql.database' => $database,
            'database.connections.mysql.username' => $username,
            'database.connections.mysql.password' => $password,
        ]);

        DB::purge('mysql');

        Artisan::call('migrate', ['--force' => true]);

        return true;
    }

    public function createAdmin(string $name, string $email, string $password, string $organizationName, string $currency = 'BDT'): User
    {
        $organization = Organization::create([
            'name' => $organizationName,
            'currency' => $currency,
            'timezone' => 'UTC',
        ]);

        return User::create([
            'organization_id' => $organization->id,
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
    }

    private function writeEnvDatabase(string $host, int|string $port, string $database, string $username, ?string $password): void
    {
        $path = base_path('.env');

        if (! file_exists($path)) {
            $path = base_path('.env.example');
        }

        $env = file_get_contents($path);

        // Uncomment and set the whole DB block regardless of comments in the file.
        $env = preg_replace('/^\s*#?\s*DB_CONNECTION=.*$/m', 'DB_CONNECTION=mysql', $env);
        $env = preg_replace('/^\s*#?\s*DB_HOST=.*$/m', "DB_HOST={$host}", $env);
        $env = preg_replace('/^\s*#?\s*DB_PORT=.*$/m', "DB_PORT={$port}", $env);
        $env = preg_replace('/^\s*#?\s*DB_DATABASE=.*$/m', "DB_DATABASE={$database}", $env);
        $env = preg_replace('/^\s*#?\s*DB_USERNAME=.*$/m', "DB_USERNAME={$username}", $env);
        $env = preg_replace('/^\s*#?\s*DB_PASSWORD=.*$/m', 'DB_PASSWORD='.$password, $env);

        file_put_contents(base_path('.env'), $env);
    }
}
