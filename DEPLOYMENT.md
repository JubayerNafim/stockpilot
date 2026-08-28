# StockPilot — Production Deployment & Operations Guide

This guide covers deploying and operating both parts of StockPilot in production:
1. **The Web Portal (`portal/`)**: Laravel 13 + React 19 SPA backend and administration portal.
2. **The WooCommerce Plugin (`stockpilot-for-woocommerce/`)**: WordPress bridge plugin installed on your WooCommerce store.

---

## Table of Contents

- [Deployment Target Assumptions](#deployment-target-assumptions)
- [Prerequisites & System Requirements](#prerequisites--system-requirements)
  - [Web Portal Prerequisites](#web-portal-prerequisites)
  - [WooCommerce Store Prerequisites](#woocommerce-store-prerequisites)
- [Deployment Bundles (Build Once, Deploy Anywhere)](#deployment-bundles-build-once-deploy-anywhere)
- [Target 1: Shared Hosting (cPanel / DirectAdmin / Web Shell)](#target-1-shared-hosting-cpanel--directadmin--web-shell)
  - [Step 1: Create Database & User](#step-1-create-database--user)
  - [Step 2: Create Subdomain & Set Document Root](#step-2-create-subdomain--set-document-root)
  - [Step 3: Upload & Extract Portal Archive](#step-3-upload--extract-portal-archive)
  - [Step 4: Configure `.env` & Generate Key](#step-4-configure-env--generate-key)
  - [Step 5: Verify Permissions & Run Setup Wizard](#step-5-verify-permissions--run-setup-wizard)
  - [Step 6: Configure Scheduled Cron Jobs](#step-6-configure-scheduled-cron-jobs)
- [Target 2: Dedicated VPS / Cloud Server (Ubuntu / Debian / Nginx / PHP-FPM)](#target-2-dedicated-vps--cloud-server-ubuntu--debian--nginx--php-fpm)
  - [Step 1: Install System Packages](#step-1-install-system-packages)
  - [Step 2: Configure MySQL Database](#step-2-configure-mysql-database)
  - [Step 3: Clone Codebase & Install Dependencies](#step-3-clone-codebase--install-dependencies)
  - [Step 4: Configure Production Environment & Cache](#step-4-configure-production-environment--cache)
  - [Step 5: Configure Nginx Virtual Host](#step-5-configure-nginx-virtual-host)
  - [Step 6: SSL with Let's Encrypt Certbot](#step-6-ssl-with-lets-encrypt-certbot)
  - [Step 7: Configure Process Manager (Supervisor)](#step-7-configure-process-manager-supervisor)
  - [Step 8: Configure System Cron](#step-8-configure-system-cron)
- [Installing & Connecting the WooCommerce Plugin](#installing--connecting-the-woocommerce-plugin)
  - [Step 1: Generate Store Credentials in Portal](#step-1-generate-store-credentials-in-portal)
  - [Step 2: Install Plugin in WordPress](#step-2-install-plugin-in-wordpress)
  - [Step 3: Configure Settings & Test Connection](#step-3-configure-settings--test-connection)
  - [Step 4: Enable Server-Side WP-Cron](#step-4-enable-server-side-wp-cron)
- [Environment Variables & Secrets Reference](#environment-variables--secrets-reference)
- [Post-Deployment Verification Checklist](#post-deployment-verification-checklist)
- [Maintenance, Ledger Repair & Rollback Procedures](#maintenance-ledger-repair--rollback-procedures)
  - [Deploying Application Updates](#deploying-application-updates)
  - [Running the Ledger Repair Tool](#running-the-ledger-repair-tool)
  - [Emergency Rollback Strategy](#emergency-rollback-strategy)

---

## Deployment Target Assumptions

StockPilot supports two primary production deployment architectures:

### 1. Shared Web Hosting (e.g., cPanel, LiteSpeed, CloudLinux)
- **Use Case:** Single-store or low-to-medium volume deployments where root server access (SSH) is limited or unavailable.
- **Workflow:** File Manager ZIP uploads, web-based setup wizard, and standard cPanel cron interface.
- **Processing Mode:** `SP_WEBHOOK_MODE=sync` (synchronous execution on incoming webhook request; no supervisor or persistent daemon needed).

### 2. Dedicated VPS / Cloud Instance (e.g., Ubuntu 22.04/24.04 LTS, AWS EC2, DigitalOcean, Hetzner)
- **Use Case:** High order volume, multi-store, or mission-critical enterprise deployments.
- **Workflow:** Git-based continuous deployment, Nginx, PHP 8.3-FPM, Supervisor process manager, and systemd cron.
- **Processing Mode:** `SP_WEBHOOK_MODE=queue` (asynchronous queue with `php artisan queue:work` supervised workers).

---

## Prerequisites & System Requirements

### Web Portal Prerequisites

| Requirement | Specification | Verification / Notes |
|---|---|---|
| **Operating System** | Linux (Ubuntu/Debian/AlmaLinux/CloudLinux) | Standard 64-bit Linux distribution. |
| **PHP Version** | **PHP 8.3.0 or higher** (e.g. PHP 8.3 / 8.4) | Check via `php -v` or cPanel **MultiPHP Manager**. |
| **Required PHP Extensions** | `pdo_mysql`, `mbstring`, `openssl`, `curl`, `fileinfo`, `intl`, `bcmath`, `json`, `xml`, `ctype`, `tokenizer` | Check via `php -m` or cPanel **Select PHP Version**. |
| **Database Server** | **MySQL 8.0+** or **MariaDB 10.11+** | `utf8mb4` character set support required. |
| **Web Server** | **Nginx** (recommended) or **Apache 2.4+** / **LiteSpeed** | Must be able to set Document Root to `portal/public`. |
| **HTTPS Certificate** | Valid SSL Certificate (Let's Encrypt / AutoSSL) | **Mandatory.** The WooCommerce plugin rejects non-HTTPS portal URLs. |
| **Outbound Email** | System `sendmail` / PHP `mail()` or SMTP Credentials | Required for real-time low-stock alerts and daily inventory digests. |

### WooCommerce Store Prerequisites

| Requirement | Specification | Notes |
|---|---|---|
| **WordPress** | **WordPress 6.0 or higher** | Core WP functions and REST API enabled. |
| **WooCommerce** | **WooCommerce 7.0 or higher** | Compatible with Classic CPT orders and High-Performance Order Storage (HPOS). |
| **PHP Version** | **PHP 7.4 to 8.3+** | Matches WordPress runtime. |
| **OpenSSL Extension** | `openssl` enabled | Required for AES-256-CBC secret encryption and HMAC-SHA256 signatures. |

---

## Deployment Bundles (Build Once, Deploy Anywhere)

The repository provides automated staging scripts in `scripts/` that build self-contained, production-ready ZIP archives:

```bash
# 1. Build Portal Upload Bundle (portal-upload.zip)
# Staging copy installs production vendor/ (--no-dev), compiles Vite React SPA,
# clears local development caches and sqlite databases.
./scripts/build-portal-upload.sh

# 2. Build WooCommerce Plugin Upload Bundle (stockpilot.zip)
# Lints all PHP files for syntax validity, excludes git/dev metadata, and zips.
./scripts/build-plugin-upload.sh
```

*(For Windows environments without bash, execute `scripts/build-portal-upload.cmd` and `scripts/build-plugin-upload.cmd`)*.

---

## Target 1: Shared Hosting (cPanel / DirectAdmin / Web Shell)

### Step 1: Create Database & User

1. Log into **cPanel** and navigate to **MySQL® Databases**.
2. Create a new database, e.g. `youruser_stockpilot`.
3. Create a new database user, e.g. `youruser_spuser` with a strong password.
4. **Add User To Database** and grant **ALL PRIVILEGES**.
5. Note the DB Name, DB User, DB Password, and Host (usually `localhost` or `127.0.0.1`).

### Step 2: Create Subdomain & Set Document Root

1. Go to **cPanel → Domains** (or **Subdomains**).
2. Create a subdomain, e.g. `inventory.yourdomain.com`.
3. **CRITICAL:** Set the **Document Root** to point specifically to the `portal/public` subdirectory:
   ```
   /home/youruser/inventory.yourdomain.com/portal/public
   ```
   *(Exposing anything other than `public/` is a security risk and will break frontend routing).*

### Step 3: Upload & Extract Portal Archive

1. Open **cPanel → File Manager** and open `/home/youruser/inventory.yourdomain.com/`.
2. Click **Upload** and upload `portal-upload.zip`.
3. Right-click `portal-upload.zip` and select **Extract**.
4. Confirm the extracted files live in `/home/youruser/inventory.yourdomain.com/portal/`.

### Step 4: Configure `.env` & Generate Key

1. In File Manager, ensure **Settings → Show Hidden Files (dotfiles)** is enabled.
2. In `/home/youruser/inventory.yourdomain.com/portal/`, copy `.env.example` to `.env`.
3. Edit `.env` with production parameters:

```env
# ── Core Application ──────────────────────────────────────────
APP_NAME=StockPilot
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://inventory.yourdomain.com

APP_LOCALE=en
APP_FALLBACK_LOCALE=en

# ── Database Connection ───────────────────────────────────────
DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=youruser_stockpilot
DB_USERNAME=youruser_spuser
DB_PASSWORD=YourSecurePasswordHere

# ── Webhook Processing Mode ───────────────────────────────────
# 'sync' runs webhooks inline — no background daemon or supervisor needed
SP_WEBHOOK_MODE=sync

# ── Session & Authentication ──────────────────────────────────
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=inventory.yourdomain.com
SESSION_SECURE_COOKIE=true

SANCTUM_STATEFUL_DOMAINS=inventory.yourdomain.com

# ── Queue, Cache & Storage ────────────────────────────────────
QUEUE_CONNECTION=database
CACHE_STORE=database
BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local

# ── Mail Configuration ────────────────────────────────────────
MAIL_MAILER=sendmail
MAIL_FROM_ADDRESS="stockpilot@yourdomain.com"
MAIL_FROM_NAME="${APP_NAME}"

# If using SMTP instead:
# MAIL_MAILER=smtp
# MAIL_HOST=mail.yourdomain.com
# MAIL_PORT=465
# MAIL_USERNAME=stockpilot@yourdomain.com
# MAIL_PASSWORD=YourEmailPassword
# MAIL_ENCRYPTION=ssl
```

> **Note on `APP_KEY`:** If `APP_KEY` is left blank, StockPilot will automatically generate and write an application key on first load. Alternatively, if SSH is available, run `php artisan key:generate`.

### Step 5: Verify Permissions & Run Setup Wizard

1. Verify folder permissions in File Manager:
   - `portal/storage/` (and all subdirectories) → `775` (or `755`)
   - `portal/bootstrap/cache/` → `775` (or `755`)
2. In cPanel, navigate to **SSL/TLS Status** and confirm **AutoSSL / Let's Encrypt** is active for `inventory.yourdomain.com`.
3. Open `https://inventory.yourdomain.com` in your browser.
4. If database credentials are in `.env`, the **`AutoMigrate`** middleware automatically runs all database migrations on the first request.
5. Follow the web prompt to create the initial **Administrator Account** and **Organization**.

### Step 6: Configure Scheduled Cron Jobs

In **cPanel → Cron Jobs**, configure the scheduler.

#### Option A: Full Laravel Scheduler (Recommended if minute-crons are permitted)
Set schedule to run every minute (`* * * * *`):
```cron
* * * * * php /home/youruser/inventory.yourdomain.com/portal/artisan schedule:run > /dev/null 2>&1
```

#### Option B: Standalone Daily Stock Digest Cron (For hosts with restricted cron intervals)
If your host restricts cron execution frequency, run the standalone script [`portal/cron/stock-summary.php`](portal/cron/stock-summary.php) daily at 8:00 AM (`0 8 * * *`):
```cron
0 8 * * * php /home/youruser/inventory.yourdomain.com/portal/cron/stock-summary.php >> /home/youruser/stockpilot-cron.log 2>&1
```
*(This standalone runner executes `warnings:evaluate` and `stock:summary`, boots under CLI or CGI/LiteSpeed PHP, and logs output directly).*

---

## Target 2: Dedicated VPS / Cloud Server (Ubuntu / Debian / Nginx / PHP-FPM)

### Step 1: Install System Packages

On Ubuntu 22.04 or 24.04 LTS:

```bash
# Add Ondřej Surý PHP PPA
sudo apt-get update
sudo apt-get install -y software-properties-common curl git unzip
sudo add-apt-repository -y ppa:ondrej/php
sudo apt-get update

# Install PHP 8.3, PHP-FPM, Extensions, Nginx, MySQL, and Supervisor
sudo apt-get install -y \
    php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml \
    php8.3-curl php8.3-bcmath php8.3-intl php8.3-zip php8.3-opcache \
    nginx mysql-server supervisor certbot python3-certbot-nginx

# Install Composer
curl -sS https://getcomposer.org/installer | sudo php -- --install-dir=/usr/local/bin --filename=composer
```

### Step 2: Configure MySQL Database

```bash
sudo mysql -u root
```

```sql
CREATE DATABASE stockpilot_prod CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'stockpilot_user'@'localhost' IDENTIFIED BY 'STRONG_DATABASE_PASSWORD';
GRANT ALL PRIVILEGES ON stockpilot_prod.* TO 'stockpilot_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

### Step 3: Clone Codebase & Install Dependencies

```bash
# Prepare application directory
sudo mkdir -p /var/www/stockpilot
sudo chown -R $USER:www-data /var/www/stockpilot

# Clone repo or extract portal-upload.zip
cd /var/www/stockpilot
git clone <your-repository-url> .
cd portal

# Install production PHP dependencies
composer install --no-dev --optimize-autoloader --no-interaction

# Install frontend dependencies & build SPA (if deploying from Git)
npm ci
npm run build
```

### Step 4: Configure Production Environment & Cache

```bash
# Create .env
cp .env.example .env
nano .env
```

Update `.env` values:
```env
APP_NAME=StockPilot
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://inventory.yourdomain.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=stockpilot_prod
DB_USERNAME=stockpilot_user
DB_PASSWORD=STRONG_DATABASE_PASSWORD

SP_WEBHOOK_MODE=queue

SESSION_DRIVER=database
SESSION_DOMAIN=inventory.yourdomain.com
SESSION_SECURE_COOKIE=true
SANCTUM_STATEFUL_DOMAINS=inventory.yourdomain.com

QUEUE_CONNECTION=database
CACHE_STORE=database
```

Generate key, run migrations, and cache production config:
```bash
php artisan key:generate --force
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Set permissions for www-data
sudo chown -R www-data:www-data /var/www/stockpilot/portal/storage /var/www/stockpilot/portal/bootstrap/cache
sudo chmod -R 775 /var/www/stockpilot/portal/storage /var/www/stockpilot/portal/bootstrap/cache
```

### Step 5: Configure Nginx Virtual Host

Create `/etc/nginx/sites-available/stockpilot.conf`:

```nginx
server {
    listen 80;
    server_name inventory.yourdomain.com;
    root /var/www/stockpilot/portal/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";
    add_header X-XSS-Protection "1; mode=block";
    add_header Referrer-Policy "strict-origin-when-cross-origin";

    index index.php index.html;
    charset utf-8;

    # Static assets caching
    location ~* \.(css|js|jpg|jpeg|png|gif|ico|woff|woff2|svg)$ {
        expires 1y;
        add_header Cache-Control "public, no-transform";
        access_log off;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
        fastcgi_read_timeout 60;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Enable site and test configuration:
```bash
sudo ln -s /etc/nginx/sites-available/stockpilot.conf /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

### Step 6: SSL with Let's Encrypt Certbot

```bash
sudo certbot --nginx -d inventory.yourdomain.com
```

### Step 7: Configure Process Manager (Supervisor)

When running `SP_WEBHOOK_MODE=queue`, Supervisor manages persistent queue workers.

Create `/etc/supervisor/conf.d/stockpilot-worker.conf`:

```ini
[program:stockpilot-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/stockpilot/portal/artisan queue:work database --sleep=3 --tries=5 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/stockpilot/portal/storage/logs/worker.log
stopwaitsecs=3600
```

Load and start Supervisor:
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start stockpilot-worker:*
```

### Step 8: Configure System Cron

Open system crontab for `www-data`:
```bash
sudo crontab -u www-data -e
```

Add the Laravel scheduler entry:
```cron
* * * * * php /var/www/stockpilot/portal/artisan schedule:run >> /dev/null 2>&1
```

---

## Installing & Connecting the WooCommerce Plugin

### Step 1: Generate Store Credentials in Portal

1. Log into the StockPilot web portal (`https://inventory.yourdomain.com`).
2. Navigate to **Stores → Connect store**.
3. Enter a store name (e.g. `Main WooCommerce Store`) and your store URL (`https://shop.yourdomain.com`).
4. Click **Generate credentials**.
5. **Copy the API Key and API Secret.** *(The secret is displayed only once).*

### Step 2: Install Plugin in WordPress

1. In WordPress Admin, navigate to **Plugins → Add New → Upload Plugin**.
2. Choose `stockpilot.zip` (built via `./scripts/build-plugin-upload.sh`).
3. Click **Install Now** and then **Activate Plugin**.

### Step 3: Configure Settings & Test Connection

1. In WordPress Admin, open the **StockPilot** menu.
2. Enter the configuration parameters:
   - **Portal URL:** `https://inventory.yourdomain.com` *(Must be HTTPS)*
   - **API Key:** `sp_...`
   - **API Secret:** `...`
3. Click **Save and test connection**.
4. Confirm success: The test sends an HMAC-signed request to `/api/webhooks/test` and confirms connectivity.
5. On initial activation, the plugin automatically queues and pushes your **entire product catalog** to the portal.

### Step 4: Enable Server-Side WP-Cron

By default, WordPress cron only triggers when a user visits the site. To guarantee instantaneous webhook queue dispatch and hourly reconciliation on low-traffic stores:

1. Edit your store's `wp-config.php`:
   ```php
   define('DISABLE_WP_CRON', true);
   ```
2. Add a server cron job on the WordPress hosting server:
   ```cron
   * * * * * php /path/to/wordpress/public_html/wp-cron.php > /dev/null 2>&1
   ```

---

## Environment Variables & Secrets Reference

| Variable | Production Value / Format | Security & Operational Context |
|---|---|---|
| `APP_ENV` | `production` | Disables debug stack traces and enables production optimizations. |
| `APP_DEBUG` | `false` | **Critical Security Requirement.** Prevents leaking database credentials in error traces. |
| `APP_KEY` | `base64:...` (32 chars) | AES-256 encryption key used for session encryption and stored credentials. |
| `APP_URL` | `https://inventory.yourdomain.com` | Canonical base URL. Must match protocol and domain exactly. |
| `DB_CONNECTION` | `mysql` | Production database connection driver. |
| `SP_WEBHOOK_MODE` | `sync` or `queue` | `sync` for shared hosting (inline processing); `queue` for VPS with Supervisor. |
| `SESSION_DRIVER` | `database` | Stores user sessions in the `sessions` table. |
| `SESSION_DOMAIN` | `inventory.yourdomain.com` | Scopes authentication cookies to the portal hostname. |
| `SESSION_SECURE_COOKIE` | `true` | Enforces HTTPS-only transmission for session cookies. |
| `SANCTUM_STATEFUL_DOMAINS` | `inventory.yourdomain.com` | Enables Sanctum SPA session authentication from the frontend app. |
| `QUEUE_CONNECTION` | `database` | Stores queued jobs in the `jobs` database table. |
| `CACHE_STORE` | `database` | Default cache repository. |
| `MAIL_MAILER` | `sendmail` or `smtp` | Mail driver for low-stock alerts and daily digests. |

---

## Post-Deployment Verification Checklist

Complete this checklist immediately following deployment:

- [ ] **Portal Accessibility & SSL:** Open `https://inventory.yourdomain.com` and verify valid HTTPS padlock (no mixed content warnings).
- [ ] **Administrator Login:** Log into the portal with the administrator credentials created during setup.
- [ ] **Organization & Currency:** Verify company name, currency (`BDT`, `USD`, etc.), and low-stock recipient email under **Settings**.
- [ ] **Email Delivery Test:** In **Settings**, click **Send Test Email** and confirm receipt in your inbox.
- [ ] **Store Connection Test:** In WordPress Admin, open **StockPilot** settings and click **Save and test connection**. Confirm a green success message appears.
- [ ] **Catalog Sync Verification:** In the portal, navigate to **Products & Recipes** and verify that all WooCommerce products have synced over.
- [ ] **Recipe Configuration:** Open a test product in **Products & Recipes**, add raw components and quantities, and save the recipe.
- [ ] **Order Lifecycle Verification:**
  1. Place a test order in WooCommerce for the configured product.
  2. In Portal → **Orders**, verify the order appears with `reservation_state = 'reserved'` (or `partial`).
  3. In Portal → **Items**, confirm `quantity_reserved` incremented and `available` decremented.
  4. In WooCommerce, mark the order **Completed**.
  5. In Portal → **Orders**, verify `reservation_state = 'confirmed'` and `quantity_on_hand` permanently decremented in **Items**.
  6. In Portal → **Activity Log**, verify all corresponding ledger movement entries exist with exact quantity deltas.
- [ ] **Cron Execution Verification:**
  - Manually run `php artisan warnings:evaluate` and `php artisan snapshots:capture`.
  - Check `sp_asset_snapshots` in the database or view **Reports → Asset Value** to verify the snapshot.

---

## Maintenance, Ledger Repair & Rollback Procedures

### Deploying Application Updates

To deploy code updates without downtime:

```bash
cd /var/www/stockpilot/portal

# 1. Pull latest code
git pull origin main

# 2. Update PHP dependencies
composer install --no-dev --optimize-autoloader --no-interaction

# 3. Apply any database migrations
php artisan migrate --force

# 4. Rebuild frontend assets (if changed)
npm ci
npm run build

# 5. Clear and rebuild application caches
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 6. Restart queue workers (if running SP_WEBHOOK_MODE=queue)
php artisan queue:restart
# or
sudo supervisorctl restart stockpilot-worker:*
```

### Running the Ledger Repair Tool

If an order ever caused duplicate deductions (e.g. prior to ledger idempotency updates), execute the built-in repair tool:

```bash
# 1. Analyze and review proposed corrections (Dry Run — zero writes)
php artisan ledger:cleanup

# 2. Apply the correction (Backs up affected rows to storage/app/ledger-cleanup/ before applying)
php artisan ledger:cleanup --apply
```

### Emergency Rollback Strategy

#### 1. Database Rollback
Maintain daily database dumps via cron:
```bash
# Backup command
mysqldump -u stockpilot_user -p stockpilot_prod > /var/backups/stockpilot_$(date +\%F).sql

# Restore command
mysql -u stockpilot_user -p stockpilot_prod < /var/backups/stockpilot_YYYY-MM-DD.sql
```

#### 2. Reverting Ledger Cleanup Operations
Every `php artisan ledger:cleanup --apply` execution writes a timestamped JSON snapshot of all deleted rows and prior balances to:
```
portal/storage/app/ledger-cleanup/cleanup-YYYYMMDD-HHMMSS.json
```
Use this snapshot to inspect or restore any historical balance if needed.

#### 3. Code Rollback
- On VPS: `git checkout <previous-commit-tag> && composer install --no-dev && php artisan config:cache`
- On Shared Hosting: Re-upload the previous version's `portal-upload.zip` and re-extract.
