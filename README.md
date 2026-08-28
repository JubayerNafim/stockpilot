# StockPilot — Inventory & Warehouse Management System

StockPilot is a dual-part inventory management and warehouse operations platform designed specifically for businesses that assemble and sell **kit products, multi-level assemblies, and component-based goods** (e.g., a belt kit order consumes a leather strap, buckle, warranty card, thank-you card, gift box, and courier poly mailer).

The platform tracks raw components and finished goods by exact quantity and unit cost to calculate true asset valuation, monitors low-stock thresholds, and automatically coordinates inventory across the full WooCommerce order lifecycle (**Reserve → Confirm → Release → Return**).

---

## Table of Contents

- [System Architecture](#system-architecture)
- [Repository Structure](#repository-structure)
- [Tech Stack & Dependencies](#tech-stack--dependencies)
- [Core Concepts & Mechanics](#core-concepts--mechanics)
  - [The Inventory State Machine](#the-inventory-state-machine)
  - [Multi-Level Bill of Materials (BOM)](#multi-level-bill-of-materials-bom)
  - [Append-Only Ledger & Concurrency Model](#append-only-ledger--concurrency-model)
  - [Automated Warnings & Stock Digests](#automated-warnings--stock-digests)
- [Local Development Setup](#local-development-setup)
- [Configuration & Environment Variables](#configuration--environment-variables)
- [Database Schema & Migrations](#database-schema--migrations)
- [Running the App & Background Workers](#running-the-app--background-workers)
  - [Webhook Ingestion Modes](#webhook-ingestion-modes)
  - [Scheduler & Background Cron](#scheduler--background-cron)
  - [Standalone Daily Stock Digest Script](#standalone-daily-stock-digest-script)
- [CLI Commands, Testing & Utilities](#cli-commands-testing--utilities)
  - [Automated Tests (Pest PHP)](#automated-tests-pest-php)
  - [Order Lifecycle Simulation](#order-lifecycle-simulation)
  - [Ledger Cleanup & Balance Repair](#ledger-cleanup--balance-repair)
  - [Production Bundle Builders](#production-bundle-builders)
- [WooCommerce Integration & Security](#woocommerce-integration--security)
  - [Plugin Architecture](#plugin-architecture)
  - [HMAC-SHA256 Webhook Security](#hmac-sha256-webhook-security)
  - [Outbound Queue & Reconciliation](#outbound-queue--reconciliation)
- [Troubleshooting & Common Pitfalls](#troubleshooting--common-pitfalls)

---

## System Architecture

StockPilot operates as a decoupled dual-system architecture:

```
┌────────────────────────────────────────────────────────┐
│             WooCommerce Store (WordPress)              │
│  Plugin: stockpilot-for-woocommerce (v1.0.2)           │
│  • Captures order & product hooks (Read-Only)          │
│  • Outbound MySQL queue with lock & exp backoff        │
│  • WP-Cron dispatcher + hourly reconciliation          │
└──────────────────────────┬─────────────────────────────┘
                           │
                           │ HTTPS POST (Signed HMAC-SHA256)
                           │ Headers: X-Api-Key, X-Timestamp,
                           │          X-Nonce, X-Event-Id, X-Signature
                           ▼
┌────────────────────────────────────────────────────────┐
│             StockPilot Web Portal (Laravel 13)         │
│  • Ingestion: Deduplication (409) & Replay Defense     │
│  • Webhook Modes: 'sync' (inline) or 'queue' (worker)  │
│  • BomExpander: Multi-level recursive recipe tree      │
│  • InventoryEngine: Reserve/Confirm/Release/Return     │
│  • Append-Only Ledger: sp_item_movements audit trail   │
│  • AssetValuation & LowStockNotifier alerts            │
│  • Self-Install Guard & AutoMigrate Middleware         │
└──────────────────────────┬─────────────────────────────┘
                           │
                           │ Sanctum SPA Auth (Cookie Session)
                           ▼
┌────────────────────────────────────────────────────────┐
│         Single-Build Frontend (React 19 + Vite 8)      │
│  • Dashboard, Inventory Items, Products & Recipes BOM  │
│  • Orders & Recompute/Reprocess Tools, Activity Ledger │
│  • Reports (Asset Value & Consumption), Stores, Users  │
└────────────────────────────────────────────────────────┘
```

| Component | Directory | Purpose |
|---|---|---|
| **Web Portal** | [`portal/`](portal/) | Laravel 13 backend + React 19 SPA. Manages items, products, multi-level recipes, orders, asset reports, warnings, settings, and multi-store connections. |
| **WooCommerce Plugin** | [`stockpilot-for-woocommerce/`](stockpilot-for-woocommerce/) | WordPress plugin (PHP 7.4+, WP 6.0+). Read-only bridge that pushes catalog data, orders, status changes, and refunds to the portal. Never modifies WooCommerce data. |
| **Build Scripts** | [`scripts/`](scripts/) | Shell (`.sh`) and Windows batch (`.cmd`) scripts that stage, lint, and build production upload archives (`portal-upload.zip` and `stockpilot.zip`). |

---

## Repository Structure

```
inventory-management/
├── portal/                                # Laravel 13 + React SPA application
│   ├── app/
│   │   ├── Console/Commands/              # CLI commands
│   │   │   ├── CaptureSnapshots.php       # snapshots:capture (Asset valuation time series)
│   │   │   ├── CleanupLedger.php          # ledger:cleanup (Removes phantom duplicate confirms)
│   │   │   ├── EvaluateWarnings.php       # warnings:evaluate (Evaluates low-stock thresholds)
│   │   │   ├── SendStockSummary.php       # stock:summary (Daily stock warning/status digest)
│   │   │   └── SimulateOrders.php         # orders:simulate (Replays test fixture order events)
│   │   ├── Http/
│   │   │   ├── Controllers/Api/           # REST & Webhook endpoints
│   │   │   │   ├── AuthController.php     # Session auth & user profile
│   │   │   │   ├── CategoryController.php # Item category CRUD
│   │   │   │   ├── DashboardController.php# Summary KPIs, low-stock counts, recent activity
│   │   │   │   ├── ItemController.php     # Raw components management & stock adjustments
│   │   │   │   ├── MovementController.php # Full activity ledger query API
│   │   │   │   ├── OrderController.php    # Orders list, detail, recompute, reprocess
│   │   │   │   ├── ProductController.php  # Products & BOM recipes configuration
│   │   │   │   ├── ReportController.php   # Asset valuation & consumption analytics
│   │   │   │   ├── SettingController.php  # Org settings, status map, test email
│   │   │   │   ├── SetupController.php    # Self-installation wizard API
│   │   │   │   ├── StoreController.php    # WooCommerce connection management & key rotation
│   │   │   │   ├── UserController.php     # Team member management & roles
│   │   │   │   └── WebhookController.php  # HMAC-authenticated order/product ingress
│   │   │   └── Middleware/
│   │   │       ├── AuthenticateStoreKey.php # HMAC-SHA256 store authentication
│   │   │       ├── AutoMigrate.php        # Auto-runs pending migrations on boot/request
│   │   │       └── EnsureRole.php         # Role-based authorization (admin, manager, staff)
│   │   ├── Jobs/
│   │   │   └── ProcessWebhookEvent.php    # Queue job for async webhook execution
│   │   ├── Mail/
│   │   │   ├── LowStockAlert.php          # Real-time threshold crossing alert
│   │   │   └── StockSummary.php           # Daily stock summary email
│   │   ├── Models/                        # 18 Eloquent models
│   │   └── Services/
│   │       ├── AssetValuation.php         # Valuation of items + finished products
│   │       ├── BomExpander.php            # Recursive multi-level recipe expander & cycle guard
│   │       ├── InventoryEngine.php        # Core Reserve/Confirm/Release/Return state machine
│   │       ├── LowStockNotifier.php       # Warning triggers with cooldown enforcement
│   │       ├── OrderRecomposer.php        # Recipe drift detection & cache reconciliation
│   │       ├── ProductSyncProcessor.php   # WooCommerce product catalog metadata sync
│   │       ├── SettingService.php         # Organization settings & status mapping
│   │       └── SetupService.php           # First-load self-installation wizard
│   ├── config/                            # App, auth, sanctum, stockpilot configs
│   ├── cron/
│   │   └── stock-summary.php              # Standalone CLI/CGI cron runner for shared hosting
│   ├── database/
│   │   └── migrations/                    # 13 database migration definitions
│   ├── public/                            # Web root (index.php, Vite build artifacts)
│   ├── resources/
│   │   ├── js/                            # React 19 TypeScript SPA
│   │   │   ├── components/                # Layout, navigation, UI components (Modal, Table, etc.)
│   │   │   ├── lib/                       # API client (Axios), auth provider, formatting utils
│   │   │   └── pages/                     # Dashboard, Items, Products, Orders, Reports, Activity, etc.
│   │   └── views/                         # app.blade.php (SPA shell) & email templates
│   ├── routes/
│   │   ├── api.php                        # API endpoints & webhook ingress routes
│   │   ├── console.php                    # Task scheduling definitions
│   │   └── web.php                        # Catch-all SPA route
│   ├── tests/
│   │   ├── Feature/                       # Pest tests (InventoryEngine, MultiLevelBom, StockIntegrity, etc.)
│   │   └── fixtures/orders.json           # Order simulation fixture data
│   ├── composer.json                      # PHP dependencies
│   ├── package.json                       # Frontend dependencies & scripts
│   └── vite.config.js                     # Vite build configuration
├── stockpilot-for-woocommerce/            # WooCommerce WordPress plugin
│   ├── admin/                             # WP Admin settings & test connection UI
│   ├── includes/
│   │   ├── class-spw-capture.php          # Read-only order/product action hooks
│   │   ├── class-spw-cron.php             # Queue processing & hourly reconciliation jobs
│   │   ├── class-spw-encryption.php       # AES-256-CBC encryption for API secret
│   │   ├── class-spw-payload.php          # Normalizes order/product payloads
│   │   ├── class-spw-plugin.php           # Core activation, schema installer, schedules
│   │   ├── class-spw-queue.php            # Persistent outbound queue with locking & backoff
│   │   ├── class-spw-sender.php           # HMAC-SHA256 signed HTTP transport
│   │   └── class-spw-settings.php         # WordPress options management
│   ├── stockpilot-for-woocommerce.php     # Main plugin bootstrap
│   └── uninstall.php                      # Clean table removal on uninstall
├── scripts/
│   ├── build-portal-upload.sh / .cmd      # Production bundle builder for Web Portal
│   └── build-plugin-upload.sh / .cmd      # Production bundle builder for WordPress Plugin
├── DEPLOYMENT.md                          # Production deployment & operations guide
└── README.md                              # This document
```

---

## Tech Stack & Dependencies

### Backend (Portal)
- **Runtime:** PHP `^8.3`
- **Framework:** Laravel `^13.8`
- **Authentication:** Laravel Sanctum `^4.3` (SPA cookie session auth)
- **Database:** MySQL 8.0+ / MariaDB 10.11+ (SQLite supported for local development and testing)
- **Testing:** Pest PHP `^4.7`, Pest Laravel Plugin `^4.1`, PHPUnit `^12.5.12`, Mockery, Faker

### Frontend (Portal)
- **Library:** React `^19.2.8` & React DOM `^19.2.8`
- **Language / Tooling:** TypeScript `^7.0.2`, Vite `^8.0.0`, `@vitejs/plugin-react ^6.0.5`, `laravel-vite-plugin ^3.1`
- **Styling:** Tailwind CSS `^4.0.0`, `@tailwindcss/vite ^4.0.0`
- **State & Data Fetching:** TanStack React Query `^5.101.4`, Axios `^1.19.0`
- **Routing:** React Router DOM `^7.18.2`

### WordPress Plugin
- **WordPress Compatibility:** WordPress 6.0+
- **PHP Compatibility:** PHP 7.4+ (fully compatible with PHP 8.0–8.3)
- **WooCommerce Compatibility:** Classic Custom Post Type orders & High-Performance Order Storage (HPOS)

---

## Core Concepts & Mechanics

### The Inventory State Machine

StockPilot maps WooCommerce order statuses to four deterministic inventory lifecycle actions:

| WooCommerce Status (Default) | State Transition | Stock Impact |
|---|---|---|
| `pending`, `pending-payment`, `on-hold`, `processing`, `confirmed` | **RESERVE** | Increments `quantity_reserved` on raw items and finished products. Items remain on hand, but `available` (`on_hand - reserved`) decreases. Order status set to `reserved` (or `partial` if insufficient stock). |
| `completed` | **CONFIRM** | Decrements `quantity_on_hand` and decrements `quantity_reserved`. Components are permanently deducted. If an order transitions directly to completed without prior reserve, it directly deducts from on-hand. Clamped if `allow_negative_stock` is false, marking order `mismatch`. |
| `cancelled`, `failed`, `trash` | **RELEASE** | Decrements `quantity_reserved`. Unshipped inventory is returned to available stock. |
| `refunded`, `returned`, `returned-with-charge` | **RETURN** | Increments `quantity_on_hand` proportional to refunded line items (`refunds` array). Restocks components and records any associated return fee. |

> **Custom Status Mappings:** Status mappings can be customized per organization in **Settings → Order Status Mapping** (stored in `sp_settings` as `status_map`).

```
                  ┌──────────────┐
                  │ Order Placed │
                  └──────┬───────┘
                         │
                         ▼
        ┌──────────────────────────────────┐
        │ RESERVE                          │
        │ • Lock raw items & sub-products  │
        │ • Lock finished sellable units   │
        │ • State: 'reserved' / 'partial'  │
        └──────┬────────────────────┬──────┘
               │                    │
   Order Completed                  │ Order Cancelled / Failed
               │                    │
               ▼                    ▼
┌───────────────────────────┐ ┌───────────────────────────┐
│ CONFIRM                   │ │ RELEASE                   │
│ • Deduct quantity_on_hand │ │ • Free quantity_reserved  │
│ • Clear quantity_reserved │ │ • State: 'released'       │
│ • State: 'confirmed'      │ └───────────────────────────┘
└──────────────┬────────────┘
               │
         Order Refunded
               │
               ▼
┌───────────────────────────┐
│ RETURN                    │
│ • Restock on-hand by qty  │
│ • Record return charges   │
│ • State: 'returned'       │
└───────────────────────────┘
```

### Multi-Level Bill of Materials (BOM)

Products can consume **raw items** (e.g., leather, buckle, box) and **sub-products** (e.g., a pre-assembled belt or wallet):

1. **Physical Sellable Unit Deduction:** Every order line automatically tracks and reserves the sellable product's own physical stock (`quantity_on_hand` / `quantity_reserved`) in addition to its recipe components.
2. **Recursive Expansion (`BomExpander`):** Recursively traverses sub-products up to a safety depth of 10 levels with active cycle detection (`cycle_error`).
3. **Packaging / Component Deduplication:** Direct components defined on the top-level sellable product take precedence over duplicate components in sub-products. For example, if a "Combo Box" explicitly includes `1 × Courier Poly`, it will not stack the courier poly mailers defined on the individual belt and wallet sub-recipes.

### Append-Only Ledger & Concurrency Model

Every inventory adjustment is permanently recorded in the `sp_item_movements` ledger:

- **No Destructive Edits:** Movements (`initial`, `purchase_in`, `adjustment`, `reserve`, `confirm`, `release`, `return`, `sync_fix`) are append-only.
- **Reversal Pointers:** Releases and returns link directly to the original movement ID via `reversal_of`.
- **Deadlock Protection:** When locking entities in database transactions (`lockForUpdate()`), items and products are sorted in ascending ID order (`orderBy('id')`) across all affected tables.
- **Idempotency Verification:** Before executing a reserve or confirm, `InventoryEngine` consults the ledger (`sp_item_movements`) for existing movements for that `(order_id, entity_id)` to prevent double-reserving or phantom double-deductions upon replayed webhooks.

### Automated Warnings & Stock Digests

- **Real-Time Threshold Crossings (`LowStockNotifier`):** Triggered when an item or product drops to or below `low_stock_threshold`. Sends a `LowStockAlert` email to the organization's `low_stock_email`.
- **Alert Cooldown:** A configurable cooldown window (default: 24 hours, stored in `sp_settings.warning_cooldown_hours`) prevents inbox spam while stock oscillates around the threshold. Warnings automatically mark `cleared` when stock is replenished.
- **Daily 24-Hour Stock Digest:** The `stock:summary` command evaluates all active components and products. If any stock is low, it sends a low-stock warning email; otherwise, it sends a complete inventory status report.

---

## Local Development Setup

### Prerequisites

- **PHP 8.3+** with CLI extensions: `pdo_mysql`, `pdo_sqlite`, `mbstring`, `openssl`, `curl`, `fileinfo`, `intl`, `bcmath`
- **Composer 2.x**
- **Node.js 20+** and **npm**
- **MySQL 8.0+ / MariaDB 10.11+** (or SQLite for fast testing)

### Step-by-Step Installation

1. **Clone the repository:**
   ```bash
   git clone <repo-url> stockpilot
   cd stockpilot/portal
   ```

2. **Install PHP and Node dependencies:**
   ```bash
   composer install
   npm install
   ```

3. **Configure Environment:**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

4. **Database Configuration:**
   - **Option A (SQLite - Instant Local Dev):**
     Ensure `.env` has:
     ```env
     DB_CONNECTION=sqlite
     ```
     Touch the database file if missing:
     ```bash
     touch database/database.sqlite
     php artisan migrate
     ```
   - **Option B (MySQL):**
     Create a MySQL database and update `.env`:
     ```env
     DB_CONNECTION=mysql
     DB_HOST=127.0.0.1
     DB_PORT=3306
     DB_DATABASE=stockpilot
     DB_USERNAME=root
     DB_PASSWORD=
     ```
     Run migrations:
     ```bash
     php artisan migrate
     ```

5. **Build or Start Frontend:**
   - For active development with hot-module reloading:
     ```bash
     npm run dev
     ```
   - For a production bundle build:
     ```bash
     npm run build
     ```

6. **Start Development Servers:**
   - Start the Laravel local server:
     ```bash
     php artisan serve --port=8000
     ```
   - *Or run the all-in-one concurrent runner:*
     ```bash
     composer dev
     ```
     *(Runs `artisan serve`, `queue:listen`, `artisan pail` log streaming, and Vite concurrently).*

7. **Access the Portal:**
   - Open `http://localhost:8000`.
   - On a fresh database, the **Self-Installation Wizard** guides you through creating the initial administrator account and organization.

---

## Configuration & Environment Variables

Key configuration variables in `portal/.env`:

| Variable | Default | Purpose |
|---|---|---|
| `APP_NAME` | `StockPilot` | Application name. |
| `APP_ENV` | `local` | `local` for development, `production` for live deployment. |
| `APP_KEY` | *(empty)* | 32-character AES encryption key (generated via `php artisan key:generate`). |
| `APP_DEBUG` | `true` | Debug mode. **Must be `false` in production.** |
| `APP_URL` | `http://localhost` | Canonical root URL of the portal. |
| `DB_CONNECTION` | `sqlite` | Database driver (`mysql`, `mariadb`, or `sqlite`). |
| `DB_HOST` | `127.0.0.1` | Database host. |
| `DB_PORT` | `3306` | Database port. |
| `DB_DATABASE` | `stockpilot` | Database name. |
| `DB_USERNAME` | `root` | Database user. |
| `DB_PASSWORD` | *(empty)* | Database password. |
| `SP_WEBHOOK_MODE` | `sync` | Webhook ingestion mode: `sync` (inline execution, zero-queue worker setup) or `queue` (dispatches to queue worker). |
| `SESSION_DRIVER` | `database` | Session storage driver. |
| `SESSION_DOMAIN` | `null` | Domain scope for session cookies (e.g. `inventory.yourdomain.com`). |
| `SESSION_SECURE_COOKIE` | `false` | Set to `true` when running on HTTPS. |
| `SANCTUM_STATEFUL_DOMAINS` | `localhost:8000` | Comma-separated list of domains for Sanctum SPA cookie authentication. |
| `QUEUE_CONNECTION` | `database` | Queue backend driver (`database`, `redis`, `sync`). |
| `CACHE_STORE` | `database` | Cache store (`database`, `redis`, `file`). |
| `MAIL_MAILER` | `sendmail` | Email driver (`sendmail`, `smtp`, `log`). |
| `MAIL_FROM_ADDRESS` | `stockpilot@yourdomain.com` | From address for system and alert emails. |

---

## Database Schema & Migrations

StockPilot includes 13 migration files managing 18 core models:

| Table Name | Associated Model | Description |
|---|---|---|
| `sp_organizations` | `Organization` | Tenant / company profile, default currency (`BDT`, `USD`, etc.), low-stock alert recipient email. |
| `sp_users` | `User` | Application users with roles (`admin`, `manager`, `staff`). |
| `sp_store_connections` | `StoreConnection` | Connected WooCommerce stores, API key hashes (SHA-256), encrypted secrets (AES-256-CBC), sync counters, health metrics. |
| `sp_categories` | `Category` | Hierarchical categorization for raw items and materials. |
| `sp_items` | `Item` | Raw materials/components (SKU, unit, cost price, on-hand qty, reserved qty, low-stock threshold). |
| `sp_products` | `Product` | Sellable products (WooCommerce synced or manual), finished-goods stock (`quantity_on_hand`, `quantity_reserved`), unit cost, pricing, categories. |
| `sp_bom_lines` | `BomLine` | Bill of materials definitions linking a product to raw items (`component_type='item'`) or sub-products (`component_type='product'`). |
| `sp_item_movements` | `ItemMovement` | Append-only inventory transaction ledger tracking deltas (`qty_delta`, `reserved_delta`), balances after, reference types (`order`, `adjustment`, `sync_fix`), and reversal links. |
| `sp_orders` | `Order` | Synced WooCommerce orders, financial totals, customer info, reservation state (`none`, `reserved`, `confirmed`, `released`, `returned`, `partial`), sync status (`pending`, `processed`, `unmapped`, `mismatch`, `error`). |
| `sp_order_items` | `OrderItem` | Individual line items on an order (WooCommerce item ID, product reference, quantity, price). |
| `sp_order_item_components`| `OrderItemComponent` | Snapshotted component requirements for each order line, reservation status, confirmed qty, returned qty. |
| `sp_order_status_events` | `OrderStatusEvent` | Historical audit log of all WooCommerce order status changes. |
| `sp_webhook_events` | `WebhookEvent` | Ingested webhook payloads with deduplication constraints (`store_id + event_id` and `store_id + nonce`). |
| `sp_stock_adjustments` | `StockAdjustment` | Manual stock adjustments (`add`, `remove`) with user audit trail and reason. |
| `sp_stock_warnings` | `StockWarning` | Active and historical low-stock warning records with cooldown timers. |
| `sp_asset_snapshots` | `AssetSnapshot` | Daily snapshots of total asset value and category valuations for time-series charts. |
| `sp_audit_log` | `AuditLog` | User actions audit log with before/after state payloads. |
| `sp_settings` | `Setting` | Organization-scoped JSON configuration (status maps, warning cooldowns, negative stock toggles). |

### Automatic Migrations (`AutoMigrate`)

The `AutoMigrate` middleware runs automatically during web requests:
- If `sp_organizations` does not exist on a reachable database, it automatically executes all migrations (`migrate --force`).
- On existing installations, it checks for and applies any newly shipped migration files dynamically, preventing `500` errors after updates.

---

## Running the App & Background Workers

### Webhook Ingestion Modes

Configured via `SP_WEBHOOK_MODE` in `.env`:

1. **`SP_WEBHOOK_MODE=sync` (Default - Recommended for Shared Hosting):**
   - Webhook payloads are processed synchronously inside the incoming HTTP request.
   - Requires **no background queue worker process**.
   - If processing fails, the transaction rolls back so the WooCommerce plugin's retry mechanism can cleanly re-attempt delivery.
2. **`SP_WEBHOOK_MODE=queue` (Recommended for High-Volume VPS):**
   - Webhooks are stored in `sp_webhook_events` and dispatched to the Laravel queue (`ProcessWebhookEvent`).
   - Requires a persistent queue worker daemon:
     ```bash
     php artisan queue:work database --sleep=3 --tries=5
     ```

### Scheduler & Background Cron

Add the standard Laravel scheduler cron to the hosting server (runs every minute):

```cron
* * * * * cd /path/to/portal && php artisan schedule:run >> /dev/null 2>&1
```

Scheduled tasks configured in `routes/console.php`:
- `snapshots:capture` — Daily at `00:05` (records asset valuation snapshot).
- `warnings:evaluate` — Hourly (evaluates low-stock thresholds across all items and products).
- `queue:work --once --stop-when-empty` — Every minute (drains any pending database queue jobs on servers without a continuous supervisor worker).

### Standalone Daily Stock Digest Script

If you cannot run `schedule:run` every minute on shared hosting, point a daily cron directly at [`portal/cron/stock-summary.php`](portal/cron/stock-summary.php):

```cron
0 8 * * * php /path/to/portal/cron/stock-summary.php >> /path/to/stockpilot-cron.log 2>&1
```

- Boots the application standalone under any CLI, CGI, or LiteSpeed PHP binary.
- Evaluates stock warnings and emails the daily inventory digest.

---

## CLI Commands, Testing & Utilities

### Automated Tests (Pest PHP)

Run the full test suite:

```bash
php artisan test
# or
composer test
```

Key test suites:
- `tests/Feature/InventoryEngineTest.php`: Tests reserve, confirm, release, return-with-charge, out-of-order completions, partial reservations, and duplicate webhooks.
- `tests/Feature/MultiLevelBomTest.php`: Tests recursive multi-level BOM expansion, combo kit deduplication, finished-goods stock consumption, and cycle prevention.
- `tests/Feature/StockIntegrityTest.php`: Tests concurrent order simulations, inventory reconciliation, and ledger balance consistency.
- `tests/Feature/LedgerCleanupTest.php`: Tests phantom deduction detection and safe balance restorations.
- `tests/Feature/AutoMigrateTest.php`: Tests automated schema installation and incremental migration execution.
- `tests/Feature/LowStockMailTest.php`: Tests threshold evaluation, cooldown suppression, and mailable rendering.

### Order Lifecycle Simulation

Simulate realistic WooCommerce order events through the inventory engine using JSON fixtures without needing an active WooCommerce store:

```bash
php artisan orders:simulate tests/fixtures/orders.json
```

### Ledger Cleanup & Balance Repair

If historical orders ever caused duplicate confirm deductions (e.g., prior to idempotent ledger guards), the built-in repair tool safely detects and resolves them:

```bash
# 1. Dry run (Inspects ledger, reports phantom rows, changes nothing):
php artisan ledger:cleanup

# 2. Scope to a single item or product:
php artisan ledger:cleanup --item=12
php artisan ledger:cleanup --product=5

# 3. Apply cleanup (Writes JSON backup to storage/app/ledger-cleanup/ and corrects balances):
php artisan ledger:cleanup --apply
```

### Production Bundle Builders

Create standalone distribution packages for deployment:

```bash
# Build web portal production bundle -> produces portal-upload.zip (~8 MB)
./scripts/build-portal-upload.sh

# Build WordPress plugin bundle -> lints PHP and produces stockpilot.zip (~20 KB)
./scripts/build-plugin-upload.sh
```

*(Windows users can execute the matching `.cmd` scripts in `scripts/`)*.

---

## WooCommerce Integration & Security

### Plugin Architecture

The **StockPilot for WooCommerce** plugin (`stockpilot-for-woocommerce/`) is a **100% read-only bridge**:
- **Zero Write Operations:** Never updates WooCommerce order statuses, never modifies post meta, and never invokes WooCommerce stock decrement functions.
- **Hook Watchers:** Captures `woocommerce_new_order`, `woocommerce_order_status_changed`, `woocommerce_order_refunded`, `woocommerce_new_product`, `woocommerce_update_product`, `trashed_post`, and `before_delete_post`.
- **HPOS Compatible:** Fully compatible with both classic post tables (`wp_posts`) and WooCommerce High-Performance Order Storage (`wp_wc_orders`).

### HMAC-SHA256 Webhook Security

Every webhook transmitted from WooCommerce to the portal is cryptographically signed:

$$\text{Signature} = \text{base64}\left(\text{HMAC-SHA256}\left(\text{secret}, \text{timestamp} : \text{nonce} : \text{event\_id} : \text{payload\_json}\right)\right)$$

1. **Replay Protection:** Rejects any request where `|time() - timestamp| > 300` seconds (5-minute window).
2. **Deduplication:** Portal enforces unique database constraints on `(store_id, event_id)` and `(store_id, nonce)`. Duplicate webhook submissions return `409 Conflict` and are safely ignored.
3. **Encrypted at Rest:**
   - Portal stores the store API secret encrypted via AES-256-CBC in `sp_store_connections.api_secret_encrypted`.
   - Plugin stores the secret in WordPress options encrypted via AES-256-CBC using WordPress `AUTH_KEY` and `AUTH_SALT`.

### Outbound Queue & Reconciliation

- **Local Outbound Queue:** Payloads are stored in `{prefix}spw_outbound_queue` before transmission.
- **Exponential Backoff:** Retries failed transmissions across 12 attempts with backoff (15s up to 10 minutes) before marking dead-letter status.
- **Hourly Safety-Net Reconciliation:** Scheduled WP-Cron task (`spw_reconcile`) checks for any order or product modified in the last hour to ensure no events were missed due to network drops.

---

## Troubleshooting & Common Pitfalls

| Symptom | Probable Cause | Resolution |
|---|---|---|
| **Portal shows 500 on first load** | `storage/` or `bootstrap/cache/` directories are not writable by the web server. | Run `chmod -R 775 portal/storage portal/bootstrap/cache` or verify web server user ownership. |
| **`No application encryption key has been specified`** | `APP_KEY` in `.env` is empty. | Run `php artisan key:generate` in `portal/`. |
| **Plugin error: `Portal URL must use HTTPS`** | Plugin requires HTTPS by default. | Enable SSL on portal domain, or define `define('SPW_ALLOW_HTTP', true);` in WordPress `wp-config.php` for local testing only. |
| **Plugin error: `401 Unauthorized / Invalid signature`** | API Key or Secret mismatch between portal and plugin. | In the portal go to **Stores**, click **Rotate Key**, copy the new key/secret, and update settings in WordPress **StockPilot** menu. |
| **Orders appear as `unmapped` in portal** | WooCommerce product SKU or Name does not match any portal Product. | Go to **Products & Recipes** in the portal, ensure the product exists and has a Bill of Materials recipe attached, then click **Reprocess** on the order. |
| **Orders appear as `mismatch`** | Order confirmed more items than were available on hand while `allow_negative_stock` is `false`. | Review physical stock, make inventory adjustment in **Items**, and click **Recompute / Reprocess** on the order. |
| **Alert emails not sending** | `MAIL_MAILER` misconfigured or host blocks PHP `mail()`. | Test via **Settings → Test Email**. Switch `MAIL_MAILER=smtp` in `.env` and provide valid SMTP host credentials. |
| **Queue jobs not processing** | `SP_WEBHOOK_MODE=queue` set but no queue worker is running. | Set `SP_WEBHOOK_MODE=sync` in `.env` (for shared hosting) or run `php artisan queue:work` via Supervisor. |
| **Database `duplicate column` on migration** | Migration previously interrupted on MySQL. | The migration definitions include `Schema::hasColumn` guards; rerun `php artisan migrate --force`. |

---

## License

This project is proprietary software. All rights reserved.
