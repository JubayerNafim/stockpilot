# Deploying StockPilot to a Shared Server (cPanel)

This guide walks through deploying **both parts** on typical shared hosting:

1. The **web portal** (Laravel + React) on your cPanel host.
2. The **WordPress plugin** on your WooCommerce store.

It is written for a cPanel / File Manager workflow — no SSH required (though SSH makes a few
steps easier, and is noted where it helps).

---

## 0. Checklist — confirm before you start

| Requirement | Where to check | Why |
|---|---|---|
| **PHP 8.3 or newer** | cPanel → **MultiPHP Manager** (per-domain PHP version) | Laravel 13 requires PHP 8.3+. If your host only offers 8.1/8.2 you'll need to upgrade the plan/host. |
| **HTTPS / SSL** | cPanel → **SSL/TLS Status** (turn on AutoSSL / Let's Encrypt) | The plugin **refuses** to talk to a non-HTTPS portal. |
| **MySQL/MariaDB** | Included in most shared plans | The portal needs a database. |
| **PHP extensions** | cPanel → **Select PHP Version** → extensions list | Need `pdo_mysql`, `mbstring`, `openssl`, `curl`, `fileinfo`, `intl`, `bcmath`. Most hosts enable these by default. |
| **Cron jobs** | cPanel → **Cron Jobs** | Runs the scheduler (asset snapshots, warning sweeps). Order sync itself works without cron. |
| **PHP `mail()`** | Usually enabled on cPanel | Used for low-stock warning emails (as you requested). |

> If your host has **SSH**, the CLI steps below can be replaced with the equivalent
> `php artisan ...` commands.

---

## Part A — Deploy the web portal

### A1. Build the upload bundle (recommended)

Run the build script — it stages a **clean copy** of the portal, installs production PHP
dependencies, compiles the React app, and produces `portal-upload.zip` **without** your local
`.env`, `node_modules`, dev data or tests:

```bash
./scripts/build-portal-upload.sh
```

On Windows, double-click `scripts/build-portal-upload.cmd` (needs Git Bash), or run it from
Claude Code with `bash scripts/build-portal-upload.sh`.

Result: `portal-upload.zip` (~8 MB) with `portal/` at the archive root. Re-run it after any
code change — it does not touch your working tree.

> If your host requires it, or you prefer a manual ZIP instead: exclude `node_modules/`,
> `.env`, `database/database.sqlite` and `tests/`; keep `vendor/` and `public/build/`.

### A2. Create the MySQL database (cPanel)

1. cPanel → **MySQL® Databases**.
2. Create a database, e.g. `youruser_stockpilot`.
3. Create a database user, e.g. `youruser_sp` with a strong password.
4. **Add the user to the database** with **ALL PRIVILEGES**.
5. Note the values — you'll need them in the setup wizard:
   - Database name, username, password
   - Host: usually `localhost`

### A3. Upload the portal files

1. Decide the folder. Recommended: create a **subdomain** (cPanel → **Domains → Create a
   Subdomain**), e.g. `inventory.yourdomain.com`, and use its **document root** = the folder
   where you upload the portal. This keeps your main site untouched and gives you a clean URL.
2. Upload `portal-upload.zip` via **File Manager → Upload**, then **right-click → Extract**.
   Result: `~/inventory.yourdomain.com/portal/` (or wherever the subdomain's docroot points).

### A4. Install PHP dependencies (only if you didn't upload `vendor/`)

- **With SSH:**
  ```bash
  cd ~/inventory.yourdomain.com/portal
  composer install --no-dev --optimize-autoloader
  ```
- **Without SSH:** make sure your ZIP **included `vendor/`** (see A1). If you must install via
  File Manager, upload `composer.phar` and run it through a web shell — easiest is to ask your
  host for SSH or use a "Terminal" feature if cPanel offers one.

### A5. Point the document root at `portal/public`

Laravel's entry point is the **`public/`** folder — that's the only folder the web server should
expose.

- **Subdomain approach (recommended):** In cPanel → **Domains**, set the subdomain's
  **Document Root** to `.../portal/public`. Your site then serves exactly the right files.
- **Fallback (docroot must stay in `public_html`):** put a tiny `index.php` in
  `public_html/` that bootstraps Laravel:
  ```php
  <?php
  require __DIR__.'/../portal/public/index.php';
  ```
  (adjust the path to where you uploaded the portal). This works, but a subdomain pointing at
  `portal/public` is cleaner.

### A6. Set PHP version to 8.3+

cPanel → **MultiPHP Manager** → select the subdomain/domain → **8.3** (or 8.4). Confirm the
required extensions are listed under **Select PHP Version** → Extensions.

### A7. Create the `.env` file (no terminal needed)

1. In File Manager enable **Settings → Show Hidden Files**.
2. Copy `.env.example` to `.env` (right-click → **Copy**).
3. **Leave `APP_KEY=` empty** — StockPilot generates it automatically on first load.
4. Edit `.env` — here is the complete production template (replace the placeholders):

   ```env
   # ── Core ───────────────────────────────────────────────────────────
   APP_NAME=StockPilot
   APP_ENV=production
   APP_KEY=                                # leave empty — auto-generated on first load
   APP_DEBUG=false                         # NEVER true in production
   APP_URL=https://inventory.yourdomain.com

   APP_LOCALE=en
   APP_FALLBACK_LOCALE=en
   APP_FAKER_LOCALE=en_US

   # ── Database ───────────────────────────────────────────────────────
   DB_CONNECTION=mysql
   DB_HOST=localhost                       # check the host shown in cPanel MySQL Databases
   DB_PORT=3306
   DB_DATABASE=youruser_stockpilot
   DB_USERNAME=youruser_sp
   DB_PASSWORD=Your-Strong-Password

   # ── Logging ────────────────────────────────────────────────────────
   LOG_CHANNEL=stack
   LOG_STACK=single
   LOG_DEPRECATIONS_CHANNEL=null
   LOG_LEVEL=error

   # ── Sessions ───────────────────────────────────────────────────────
   SESSION_DRIVER=database
   SESSION_LIFETIME=120
   SESSION_ENCRYPT=false
   SESSION_PATH=/
   SESSION_DOMAIN=inventory.yourdomain.com
   SESSION_SECURE_COOKIE=true              # false while testing without SSL

   # ── Queue / cache / files ──────────────────────────────────────────
   QUEUE_CONNECTION=database
   CACHE_STORE=database
   BROADCAST_CONNECTION=log
   FILESYSTEM_DISK=local

   # ── Webhooks ───────────────────────────────────────────────────────
   SP_WEBHOOK_MODE=sync                    # process orders instantly, no worker needed

   # ── Sanctum SPA auth ───────────────────────────────────────────────
   SANCTUM_STATEFUL_DOMAINS=inventory.yourdomain.com

   # ── Mail (PHP mail() by default) ───────────────────────────────────
   MAIL_MAILER=sendmail
   MAIL_SCHEME=null
   MAIL_HOST=127.0.0.1
   MAIL_PORT=2525
   MAIL_USERNAME=null
   MAIL_PASSWORD=null
   MAIL_FROM_ADDRESS="stockpilot@yourdomain.com"
   MAIL_FROM_NAME="${APP_NAME}"

   # If host sendmail is unreliable, use SMTP instead:
   # MAIL_MAILER=smtp
   # MAIL_HOST=mail.yourdomain.com
   # MAIL_PORT=465
   # MAIL_USERNAME=stockpilot@yourdomain.com
   # MAIL_PASSWORD=your-smtp-password
   # MAIL_ENCRYPTION=ssl

   # ── Unused on shared hosting (defaults) ────────────────────────────
   BCRYPT_ROUNDS=12
   MEMCACHED_HOST=127.0.0.1
   REDIS_CLIENT=phpredis
   REDIS_HOST=127.0.0.1
   REDIS_PASSWORD=null
   REDIS_PORT=6379
   AWS_ACCESS_KEY_ID=
   AWS_SECRET_ACCESS_KEY=
   AWS_DEFAULT_REGION=us-east-1
   AWS_BUCKET=
   AWS_USE_PATH_STYLE_ENDPOINT=false
   VITE_APP_NAME="${APP_NAME}"
   ```

   > The three easy-to-get-wrong values: `APP_KEY=` left empty (auto-generated),
   > `APP_DEBUG=false` (hides error details from visitors), and
   > `SANCTUM_STATEFUL_DOMAINS` / `SESSION_DOMAIN` set to your real portal domain.
   > `SESSION_SECURE_COOKIE=true` only works once HTTPS is enabled.

### A8. Set folder permissions

In File Manager, set these folders to writable (usually `755` for dirs is fine, but if you see
permission errors use `775`):

- `portal/storage/` (and everything under it)
- `portal/bootstrap/cache/`

If File Manager won't let you set `775`, leave defaults — most cPanel hosts run PHP as the same
user as your files, so `755` works.

### A9. First load → the setup wizard installs the database

1. Visit `https://inventory.yourdomain.com`.
2. The **setup wizard** appears. Enter your database credentials from A2.
   - It connects to MySQL, writes the connection into `.env`, and **creates all tables itself**.
3. Create your admin account.
4. Done — you can log in.

> If you already configured MySQL directly in `.env` (A7), the first request **auto-runs the
> migrations** instead, and the wizard is skipped.

### A10. Add the cron job (cPanel)

cPanel → **Cron Jobs** → add:

```
* * * * * php /home/youruser/inventory.yourdomain.com/portal/artisan schedule:run > /dev/null 2>&1
```

(Replace the path with your real absolute path. Ask your host for the absolute home path, often
`/home/youruser/...`.)

- This runs the scheduler: nightly **asset-value snapshots** and the hourly **low-stock sweep**.
- **Order sync does NOT depend on cron** — with `SP_WEBHOOK_MODE=sync`, orders are processed the
  instant the plugin sends them.
- Note: some cPanel hosts limit cron to every **5 minutes** — that's fine here; the scheduler
  simply fires less often and still triggers the daily/hourly jobs on schedule.

#### A10b. Daily stock-summary email (no scheduler needed)

If you'd rather not run `schedule:run` every minute, or you simply want a **daily 24-hour stock
digest**, point a cron job directly at the standalone script `portal/cron/stock-summary.php`.
It needs no scheduler — it boots the app itself and exits when done:

```
0 8 * * * php /home/youruser/inventory.yourdomain.com/portal/cron/stock-summary.php >> /home/youruser/stockpilot-cron.log 2>&1
```

This example runs every day at 8:00 AM (adjust the time). What it does:

1. **`warnings:evaluate`** — checks every item/product for low stock, records warnings, and emails
   the immediate per-crossing alert (retrying any earlier send that failed).
2. **`stock:summary`** — emails the **low-stock address configured in portal → Settings**:
   - if anything is at or below its threshold → a **LOW-STOCK WARNING** email listing what needs
     restocking, plus the full stock status;
   - otherwise → a **STOCK STATUS REPORT** email listing the current stock of every product/item.

The script is CLI-only (opening it in a browser does nothing) and writes a readable record of each
run to stdout, which is what the `>> ... .log` above captures.

### A11. Turn on HTTPS for the portal

cPanel → **SSL/TLS Status** → enable AutoSSL / issue a Let's Encrypt cert for
`inventory.yourdomain.com`. Keep `APP_URL` as `https://...` in `.env`.

### A12. Repair movement history after the phantom-deduction bug

If your stock ever got over-deducted by old orders being re-confirmed (the
engine now prevents new ones), clean the bogus ledger rows with the built-in
repair command. It is **safe by default**:

```bash
# 1) Dry-run — reports what it would delete, changes NOTHING:
php /home/youruser/inventory.yourdomain.com/portal/artisan ledger:cleanup

# 2) Review the table, then apply (backs up every deleted row + balances first):
php /home/youruser/inventory.yourdomain.com/portal/artisan ledger:cleanup --apply
```

What it does:
- Removes only **unambiguous duplicate `confirm` rows** (an order can confirm an
  entity once per shipment; a second confirm with no return in between is a
  provable phantom over-deduction). It never touches returns or ambiguous
  cycles, and never guesses.
- Restores each affected item/product's on-hand (and reserved) balance by the
  exact phantom amount.
- `--apply` writes a JSON backup to `portal/storage/app/ledger-cleanup/` before
  changing anything, so the change is reversible.
- Optional scope: `--item=12` or `--product=5` to review/apply one entity.

After cleaning the ledger, re-check your stock against your **physical counts**
and adjust via Items/Products if the recorded starting stock was already off.

---

## Part B — Deploy the WordPress plugin

### B1. Create the connection in the portal first

1. Log into the portal → **Stores → Connect store**.
2. Name it and enter your store URL → **Generate credentials**.
3. **Copy the API key + secret** (shown only once).

### B2. Install the plugin on WooCommerce

1. Build the plugin bundle (lints every PHP file, then zips it):
   ```bash
   ./scripts/build-plugin-upload.sh
   ```
   On Windows, double-click `scripts/build-plugin-upload.cmd` (needs Git Bash), or run
   `bash scripts/build-plugin-upload.sh` from Claude Code. Result: `stockpilot.zip` (~20 KB).
2. WordPress admin → **Plugins → Add New → Upload Plugin** → choose `stockpilot.zip` → **Install Now** → **Activate**.
   (Or upload the unzipped `stockpilot-for-woocommerce/` folder into `wp-content/plugins/` via the host's File Manager.)
3. In WP admin, open the new **StockPilot** menu item.

### B3. Configure and test

1. **Portal URL**: `https://inventory.yourdomain.com`
2. **API key / secret**: paste from B1.
3. Click **Save and test connection**.
   - Success shows the portal's response.
   - On activation the plugin automatically pushes your **entire product catalog**, so your
     products appear in the portal immediately.

### B4. Reliable delivery on your store (recommended)

On low-traffic WooCommerce stores WP-Cron only runs when someone visits the site. In
`wp-config.php` add:

```php
define('DISABLE_WP_CRON', true);
```

and add a server cron on your **hosting** account:

```
* * * * * php /home/youruser/public_html/wp-cron.php > /dev/null 2>&1
```

This makes order pushes happen on time even when the store has no visitors.

---

## Part C — Test the whole system

1. In WooCommerce create a **test product** (e.g. "Belt Kit") — it appears in the portal within a minute.
2. In the portal, open **Products & Recipes** and attach its components + quantities (the recipe).
3. Place a **test order** for that product in the store.
   - Portal → **Orders**: the order appears as `processing` and **reservation = reserved**.
4. Mark the order **completed** in Woo.
   - Portal: reservation becomes **confirmed**, components deducted from on-hand.
5. Cancel / refund a test order:
   - Cancelled → reservation **released**.
   - Refunded / returned → components **added back** (charge recorded).

## Troubleshooting

| Symptom | Likely cause / fix |
|---|---|
| `No application encryption key has been specified` | `.env` `APP_KEY` empty and not auto-generated — make sure `.env` exists and is writable, then reload once. |
| Setup wizard "Could not connect" | Wrong DB host (try `localhost`), username/db-name mismatch, or the DB user wasn't granted **ALL PRIVILEGES** on that database. |
| Plugin: "Portal URL must use HTTPS" | Turn on SSL for the portal (A11) and use `https://` in the plugin settings. |
| Plugin: 401 / "key revoked" | Key/secret mismatch — regenerate in portal **Stores → Rotate key** and re-enter. |
| 500 error on the portal | Check `portal/storage/logs/laravel.log`. Most common: `storage/` not writable, or PHP version < 8.3. |
| Orders not appearing | Check the plugin's **StockPilot** settings page for queue counts / dead events, and that the store is **connected** (green) in the portal. |
| Low-stock emails not arriving | Confirm `MAIL_MAILER=sendmail` and that the address in portal **Settings** is correct; check the host's spam folder. |

## Moving to a VPS later

If you outgrow shared hosting: the same steps apply, but you'll use a real process manager
(`supervisor`) for a persistent `queue:work` worker (set `SP_WEBHOOK_MODE=queue`), a proper
scheduler cron, and a dedicated web server + PHP-FPM. Everything else stays identical.
