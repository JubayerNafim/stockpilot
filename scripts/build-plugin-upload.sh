#!/usr/bin/env bash
#
# Build stockpilot.zip — the WordPress plugin upload bundle.
#
# Lints every PHP file (aborts on syntax errors) and zips the plugin folder
# with `stockpilot-for-woocommerce/` at the archive root, which is what the
# WordPress "Upload Plugin" installer expects.
#
# Usage:
#   ./scripts/build-plugin-upload.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN="$ROOT/stockpilot-for-woocommerce"
OUT="$ROOT/stockpilot.zip"

echo "==> StockPilot for WooCommerce bundle builder"
echo "    source : $PLUGIN"
echo "    output : $OUT"

# ---------------------------------------------------------------------------
# 1. PHP lint
# ---------------------------------------------------------------------------
echo ""
echo "[1/2] PHP lint..."
if command -v php >/dev/null 2>&1; then
    ERR=0
    while IFS= read -r f; do
        if ! php -l "$f" >/dev/null 2>&1; then
            echo "    syntax error in: $f"
            ERR=1
        fi
    done < <(find "$PLUGIN" -name '*.php')
    if [ "$ERR" -ne 0 ]; then
        echo "==> Aborting: PHP syntax errors found (see above)."
        exit 1
    fi
    echo "    all PHP files OK"
else
    echo "    php not found on PATH — skipping lint"
fi

# ---------------------------------------------------------------------------
# 2. Zip (excludes build artifacts, git metadata, OS junk)
# ---------------------------------------------------------------------------
echo ""
echo "[2/2] Creating archive..."
rm -f "$OUT"

if command -v zip >/dev/null 2>&1; then
    ( cd "$ROOT" && zip -qr "$OUT" stockpilot-for-woocommerce -x "*.zip" "*/.git/*" "*/.DS_Store" )
elif command -v python3 >/dev/null 2>&1; then
    ( cd "$ROOT" && python3 - "$OUT" <<'PY'
import os, sys, zipfile
out = sys.argv[1]
src = "stockpilot-for-woocommerce"
with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
    for dirpath, _dirs, files in os.walk(src):
        for f in files:
            full = os.path.join(dirpath, f)
            norm = "/" + full.replace(os.sep, "/") + "/"
            if f.endswith(".zip") or f.startswith(".") or "/.git/" in norm or f == ".DS_Store":
                continue
            z.write(full, os.path.relpath(full, "."))
PY
    )
else
    powershell.exe -NoProfile -Command "Compress-Archive -Path '$ROOT\\stockpilot-for-woocommerce' -DestinationPath '$OUT' -Force"
fi

SIZE="$(du -h "$OUT" 2>/dev/null | cut -f1 || stat -c %s "$OUT")"
echo ""
echo "==> Done: $OUT ($SIZE)"
echo "    Install in WordPress: Plugins -> Add New -> Upload Plugin -> select this file."
