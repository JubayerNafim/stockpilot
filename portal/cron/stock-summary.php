<?php

declare(strict_types=1);

/**
 * StockPilot — daily stock-summary cron entry point.
 *
 * Add this to your hosting control panel's "Cron Jobs" and run it every 24h,
 * e.g. every morning at 8:00 AM:
 *
 *     0 8 * * * php /home/youruser/inventory.yourdomain.com/portal/cron/stock-summary.php
 *
 * Replace the path with your real absolute path (cPanel → Cron Jobs shows the
 * home path, often /home/youruser/...). You can point it at the PHP binary your
 * host recommends for CLI cron jobs.
 *
 * What it does:
 *   1. Runs `warnings:evaluate` — checks every item/product for low stock,
 *      creates warning rows, and emails the immediate one-per-crossing alert
 *      (retrying any earlier send that failed).
 *   2. Runs `stock:summary` — emails a daily digest to the low-stock address
 *      configured in portal Settings:
 *        - if anything is at/below its threshold → a LOW-STOCK WARNING email
 *          listing what needs restocking, plus the full stock status;
 *        - otherwise → a STOCK STATUS REPORT email with every product/item.
 *
 * The script refuses to run over HTTP — opening it in a browser does nothing.
 * It runs under ANY command-line PHP: the CLI binary (`PHP_SAPI = cli`) OR a
 * CGI/LiteSpeed binary invoked from the shell (e.g. `lsphp`, common on
 * cPanel — there is no REQUEST_METHOD in a shell run, so it's allowed).
 * It logs progress to stdout/stderr, so you can also redirect output to a file
 * (e.g. `... stock-summary.php >> /home/youruser/stockpilot-cron.log 2>&1`).
 */

// Every real HTTP request sets REQUEST_METHOD (GET/POST/...); no command-line
// invocation does. Use that instead of PHP_SAPI so the cron works whether the
// host's `php` is the CLI binary or a CGI/LiteSpeed variant.
if (isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(403);
    exit(1);
}

// Under the CLI SAPI PHP defines STDOUT/STDERR automatically; under a
// CGI/LiteSpeed PHP (common for cPanel cron) they are missing. Define them so
// the script runs on any SAPI — otherwise the first fwrite(STDERR, ...) throws
// "Undefined constant STDERR" and the real error never gets printed.
if (! defined('STDOUT')) {
    define('STDOUT', fopen('php://stdout', 'w'));
}
if (! defined('STDERR')) {
    define('STDERR', fopen('php://stderr', 'w'));
}

$portalDir = dirname(__DIR__);
$autoload = $portalDir.'/vendor/autoload.php';
$bootstrap = $portalDir.'/bootstrap/app.php';

if (! is_file($autoload) || ! is_file($bootstrap)) {
    fwrite(STDERR, "[StockPilot cron] Portal not found — expected: {$portalDir}\n");
    exit(1);
}

require $autoload;

// If the app itself can't boot (missing/wrong .env, DB down, missing PHP
// extension), say so on stderr instead of letting the host surface a generic
// 500 HTML page that hides the real cause.
try {
    $app = require $bootstrap;

    /** @var \Illuminate\Contracts\Console\Kernel $kernel */
    $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
} catch (\Throwable $e) {
    fwrite(STDERR, "[StockPilot cron] Could not boot the app: {$e->getMessage()}\n");
    fwrite(STDERR, "[StockPilot cron] at {$e->getFile()}:{$e->getLine()}\n");
    exit(1);
}

$exit = 0;

foreach (['warnings:evaluate', 'stock:summary'] as $command) {
    $buffer = new \Symfony\Component\Console\Output\BufferedOutput();

    try {
        $status = $kernel->call($command, [], $buffer);

        // Echo the command's output so redirecting this script to a log file
        // gives a readable record of what each run did.
        fwrite(STDOUT, "[StockPilot cron] --- {$command} ---\n");
        fwrite(STDOUT, trim((string) $buffer->fetch())."\n");

        if ($status !== 0) {
            fwrite(STDERR, "[StockPilot cron] '{$command}' exited with status {$status}\n");
            $exit = 1;
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, "[StockPilot cron] '{$command}' failed: {$e->getMessage()}\n");
        fwrite(STDERR, "[StockPilot cron] at {$e->getFile()}:{$e->getLine()}\n");
        $exit = 1;
    }
}

exit($exit);
