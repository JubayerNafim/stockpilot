# StockPilot — Inventory & Warehouse Management SaaS

A two-part system for businesses that assemble **kit products** from many components
(a belt order consumes: thank-you card, warranty card, punch tool, box, courier poly,
sticker + the belt itself). It tracks every component (quantity + cost → real asset value),
warns you when stock runs low, and automatically reserves / deducts / restocks inventory as
WooCommerce orders flow through your store.

| Part | Location | What it is |
|---|---|---|
| **Web portal** | [`portal/`](portal/) | Laravel + React SPA. Manages items, BOM recipes, orders, reports, warnings, stores. Self-installs (creates all tables from DB credentials). |
| **WooCommerce plugin** | [`stockpilot-for-woocommerce/`](stockpilot-for-woocommerce/) | WordPress plugin. Securely forwards orders, order status changes and the product catalog to the portal. **Never modifies your store.** |

```
[WooCommerce store]                    [Web portal]
  WordPress plugin  ──HTTPS + HMAC──▶  Laravel API  ──▶  MySQL/MariaDB
  • watches order hooks                  • ingest: dedup + idempotency
  • watches product hooks                • Product Catalog Sync (name, category, meta)
  • local queue + retry                  • Inventory Engine (reserve/confirm/release/return)
  • hourly reconcile (safety net)        • React SPA (single build) for staff
```

## How inventory is consumed (reserve → confirm)

| Woo status | Portal action |
|---|---|
| `pending` / `on-hold` / `processing` | **RESERVE** — components locked (still on hand, but not available) |
| `completed` | **CONFIRM** — permanently deducted from on-hand |
| `cancelled` / `failed` / `trash` | **RELEASE** — reservation returned to available |
| `refunded` / `returned` / `returned-with-charge` | **RETURN** — components added back (charge recorded) |

Every change is written to an **append-only ledger** (`sp_item_movements`); corrections are
reversal rows, never edits. The engine is idempotent across duplicate, out-of-order and
retried webhooks (unique `event_id` + nonce + a state-machine guard).

## Quick start

### 1. Web portal

Prerequisites: PHP 8.3+, Composer, Node 20+, MySQL/MariaDB.

```bash
cd portal
composer install
npm install
npm run build                # single React build into public/build
cp .env.example .env         # or edit .env; default dev DB is SQLite
php artisan serve            # or point a web server at portal/public
```

Open the site — a **setup wizard** walks you through:
1. MySQL credentials → it connects, writes `.env`, and **creates every table itself**.
2. Your admin account.

> If you configure MySQL directly in `.env`, the first web request auto-runs pending
> migrations — no manual `migrate` step needed.

### 2. WordPress plugin

1. Zip `stockpilot-for-woocommerce/` and install it in WordPress (`Plugins → Add New → Upload`).
2. In the portal open **Stores → Connect store** → copy the generated **API key + secret**.
3. Activate the plugin → **StockPilot** menu → enter the portal URL + key + secret → **Save and test connection**.
4. On activation the plugin pushes your **entire product catalog** to the portal, so products
   appear automatically. Attach a recipe to each product in the portal (**Products & Recipes**) so
   orders deduct the right components.

### 3. Using it

1. **Items** — add components (name, unit, cost, starting qty, low-stock threshold).
2. **Products & Recipes** — open a product, add its components and quantities per unit.
3. Orders now flow from WooCommerce and automatically reserve/deduct/restock components.
4. Low-stock items trigger an email to your configured address (via PHP mail).

## Build scripts (deployment bundles)

| Command | Produces | Use |
|---|---|---|
| `scripts/build-portal-upload.sh` | `portal-upload.zip` (~8 MB) | Upload the portal to your host. Builds from a clean copy; lints/builds everything. |
| `scripts/build-plugin-upload.sh` | `stockpilot.zip` (~20 KB) | Upload the plugin to WordPress. Lints PHP, then zips for WP's upload installer. |

Windows: double-click the matching `.cmd` in `scripts/` (needs Git Bash), or run
`bash scripts/<script>` from Claude Code.

## Documentation

- [Portal setup & operations](portal/README.md) — config, queue worker, scheduler, deploy checklist.
- [Plugin](stockpilot-for-woocommerce/README.md) — installation, security, reliability model.
- [Deployment](DEPLOYMENT.md) — step-by-step shared-hosting (cPanel) guide.
- [Planned architecture](.claude/plans/rosy-honking-sutherland.md) — full design doc.

## Security model

- Portal: HTTPS-only; Sanctum cookie sessions for staff; per-store API key + secret.
- Plugin → portal: every request signed `HMAC-SHA256(timestamp:nonce:event_id:body)` with
  replay protection (±5 min), event dedup, and a local encrypted secret.
- Plugin is **read-only**: it never writes orders/products, never touches Woo stock functions.
- Secrets encrypted at rest on both sides.
