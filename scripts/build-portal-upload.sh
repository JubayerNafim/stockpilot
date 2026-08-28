#!/usr/bin/env bash
#
# Build portal-upload.zip — a self-contained bundle for deploying the StockPilot
# portal to a shared host (see DEPLOYMENT.md Part A).
#
# It builds from a clean staging copy, so your working tree is never modified
# and dev dependencies stay installed locally.
#
# Usage:
#   ./scripts/build-portal-upload.sh
#
# Optional overrides (if a tool isn't on PATH):
#   COMPOSER_BIN="php /path/to/composer.phar" ./scripts/build-portal-upload.sh
#   NPM_BIN="/path/to/npm"                      ./scripts/build-portal-upload.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORTAL="$ROOT/portal"
OUT="$ROOT/portal-upload.zip"

COMPOSER_BIN="${COMPOSER_BIN:-composer}"
NPM_BIN="${NPM_BIN:-npm}"

STAGING_PARENT="$(mktemp -d)"
STAGING="$STAGING_PARENT/portal"
cleanup() { rm -rf "$STAGING_PARENT"; }
trap cleanup EXIT

echo "==> StockPilot portal bundle builder"
echo "    source : $PORTAL"
echo "    output : $OUT"

# ---------------------------------------------------------------------------
# 1. Stage the source tree (excludes vendor, node_modules, .env, dev data)
# ---------------------------------------------------------------------------
echo ""
echo "[1/5] Staging source files..."
mkdir -p "$STAGING"

for item in app bootstrap config cron database public resources routes; do
    [ -d "$PORTAL/$item" ] && cp -R "$PORTAL/$item" "$STAGING/"
done

# storage skeleton (empty framework dirs; Laravel recreates files at runtime)
mkdir -p "$STAGING/storage/app/public"
mkdir -p "$STAGING/storage/framework/cache/data"
mkdir -p "$STAGING/storage/framework/sessions"
mkdir -p "$STAGING/storage/framework/testing"
mkdir -p "$STAGING/storage/framework/views"
mkdir -p "$STAGING/storage/logs"
touch "$STAGING/storage/app/public/.gitkeep" \
      "$STAGING/storage/framework/cache/data/.gitkeep" \
      "$STAGING/storage/framework/sessions/.gitkeep" \
      "$STAGING/storage/framework/testing/.gitkeep" \
      "$STAGING/storage/framework/views/.gitkeep" \
      "$STAGING/storage/logs/.gitkeep"

# Root files needed on the server (no tests, no .env)
for f in artisan composer.json composer.lock package.json package-lock.json \
         vite.config.js tsconfig.json .env.example; do
    [ -e "$PORTAL/$f" ] && cp "$PORTAL/$f" "$STAGING/"
done

# Remove any local dev database that may live inside database/
rm -f "$STAGING/database/database.sqlite"

# ---------------------------------------------------------------------------
# 2. Install production PHP dependencies inside the staging copy
# ---------------------------------------------------------------------------
echo ""
echo "[2/5] Installing composer dependencies (no-dev)..."
# NOTE: $COMPOSER_BIN is intentionally unquoted so a value like
# "php /path/to/composer.phar" splits into command + argument.
(
    cd "$STAGING"
    $COMPOSER_BIN install --no-dev --no-interaction --no-progress --optimize-autoloader
)

# ---------------------------------------------------------------------------
# 3. Build the React frontend inside the staging copy
# ---------------------------------------------------------------------------
echo ""
echo "[3/5] Building React frontend..."
(
    cd "$STAGING"
    $NPM_BIN ci --no-audit --no-fund 2>/dev/null || $NPM_BIN install --no-audit --no-fund
    $NPM_BIN run build
)

# ---------------------------------------------------------------------------
# 4. Clean runtime state so the server starts fresh
# ---------------------------------------------------------------------------
echo ""
echo "[4/5] Cleaning runtime state..."
rm -f "$STAGING"/storage/logs/*.log
find "$STAGING/storage/framework" -type f ! -name '.gitkeep' -delete
rm -f "$STAGING"/bootstrap/cache/*.php
rm -rf "$STAGING/node_modules"
rm -f "$STAGING/.env"
# Reset APP_KEY so the server generates its own on first boot.
sed -i 's/^APP_KEY=.*/APP_KEY=/' "$STAGING/.env.example" 2>/dev/null || true

# ---------------------------------------------------------------------------
# 5. Zip it (portal/ at the root of the archive)
# ---------------------------------------------------------------------------
echo ""
echo "[5/5] Creating archive..."
rm -f "$OUT"

if command -v zip >/dev/null 2>&1; then
    ( cd "$STAGING_PARENT" && zip -qr "$OUT" portal )
elif command -v python3 >/dev/null 2>&1; then
    ( cd "$STAGING_PARENT" && python3 - "$OUT" <<'PY'
import os, sys, zipfile
out, root = sys.argv[1], "portal"
with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
    for dirpath, _dirs, files in os.walk(root):
        for f in files:
            full = os.path.join(dirpath, f)
            z.write(full, os.path.join("portal", os.path.relpath(full, root)))
PY
    )
else
    powershell.exe -NoProfile -Command "Compress-Archive -Path '$STAGING_PARENT/portal' -DestinationPath '$OUT' -Force"
fi

SIZE="$(du -h "$OUT" 2>/dev/null | cut -f1 || stat -c %s "$OUT")"
echo ""
echo "==> Done: $OUT ($SIZE)"
echo "    Extract on the server, set document root to <extract>/portal/public,"
echo "    copy .env.example to .env, then load the site to run the setup wizard."
