# StockPilot for WooCommerce

A **read-only** bridge between your WooCommerce store and the StockPilot inventory portal.
It forwards orders, order status changes and your product catalog to the portal. It never
modifies anything in WooCommerce.

## Installation

1. Zip this folder and install it: **Plugins → Add New → Upload Plugin**.
2. In the portal open **Stores → Connect store** and copy the generated **API key + secret**.
3. Activate the plugin, open **StockPilot**, enter:
   - **Portal URL** — your portal address (HTTPS).
   - **API key** and **API secret**.
4. Click **Save and test connection**.

On activation the plugin pushes your **entire product catalog** so products appear in the
portal immediately. In the portal, attach a **recipe** (BOM) to each product so orders deduct
the right components automatically.

## What it sends

| Event | Trigger | Payload |
|---|---|---|
| `order.sync` | new order, status change, refund, REST edit | id, status, dates, customer, totals, line items (product, qty, price), refunds per line |
| `product.sync` | product create/update, trash/untrash, REST edit | id, name, slug, sku, type, status, prices, stock, categories, image, modified date |
| `product.delete` | product permanently deleted | id + modified date |

## Reliability

- **Local outbound queue** (`{prefix}spw_outbound_queue`) with claim-locking, exponential
  backoff (15s → 10 min) and a dead-letter state after 12 attempts.
- **WP-Cron** drains the queue every minute and runs an **hourly reconciliation** that
  re-checks every order/product modified since the last sync — a safety net for missed hooks.
- **Dedup**: each event carries a stable `event_id`; the portal rejects duplicates (`409`).
- **Read-only**: the plugin only calls `wc_get_order()`, `wc_get_product()` and reads post
  data. It never calls stock functions, never updates order status, never writes post meta.

## Security

- Portal URL must be **HTTPS** (except when `SPW_ALLOW_HTTP` is defined for local testing).
- Every request is signed `HMAC-SHA256( timestamp : nonce : event_id : body )` and carries a
  unique nonce + timestamp window, so requests cannot be replayed.
- The API secret is stored **encrypted at rest** using AES-256-CBC keyed from `AUTH_KEY`/`AUTH_SALT`.
- If the portal revokes the key, the plugin disables the connection and notifies the admin.

## Operational tips

- On low-traffic stores WP-Cron only runs when someone visits — enable a real server cron and
  set `DISABLE_WP_CRON=true` in `wp-config.php` for reliable delivery:

  ```
  * * * * * php /path/to/wp-cron.php
  ```

- Deactivate ≠ uninstall: your queue and sync log are kept. Only **uninstalling** the plugin
  drops its tables (`uninstall.php`).
