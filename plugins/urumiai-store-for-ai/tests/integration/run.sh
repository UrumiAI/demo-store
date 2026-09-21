#!/usr/bin/env bash
# Integration test: this plugin against a REAL WordPress + WooCommerce.
#
# The unit suite (../store-for-ai.test.js) drives the framework-agnostic core
# against a fixture. It cannot exercise the four files that need WordPress:
#   class-rest-controller.php, class-woocommerce-adapter.php,
#   class-transient-session-store.php, class-checkout-handoff.php
#
# This does, using WordPress Playground (WordPress + PHP + SQLite in wasm), so
# it needs no MySQL, no PHP extensions and no hosting account — only Node.
#
# Usage:  ./run.sh [path/to/woocommerce]
#
# With no argument it installs WooCommerce from wordpress.org. Installing it
# inside wasm is memory-hungry and can fail on a small box; passing a WooCommerce
# directory to mount instead is faster and far more reliable.
#
#   ./run.sh /path/to/demo-store/plugins/woocommerce
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "$HERE/../.." && pwd)"
WORK="${WORK_DIR:-$(mktemp -d)}"
PORT="${PORT:-9400}"
WOO_SRC="${1:-}"

echo "workspace: $WORK"
mkdir -p "$WORK/plugins/urumiai-store-for-ai" "$WORK/plugins/zz-test-seed"

cp "$PLUGIN_ROOT/urumiai-store-for-ai.php" "$WORK/plugins/urumiai-store-for-ai/"
cp -r "$PLUGIN_ROOT/urumiai-store-for-ai" "$WORK/plugins/urumiai-store-for-ai/"

{
  echo '<?php'
  echo '/**'
  echo ' * Plugin Name: ZZ Test Seed Products'
  echo ' * Description: Test fixture. Seeds WooCommerce products for the integration test.'
  echo ' * Version: 1.0.0'
  echo ' */'
  tail -n +2 "$HERE/seed-products.php"
} > "$WORK/plugins/zz-test-seed/zz-test-seed.php"

cp "$HERE/blueprint.json" "$WORK/blueprint.json"

if [ -n "$WOO_SRC" ]; then
  echo "mounting WooCommerce from $WOO_SRC"
  cp -r "$WOO_SRC" "$WORK/plugins/woocommerce"
else
  echo "installing WooCommerce from wordpress.org (slower; may OOM in wasm)"
  python3 - "$WORK/blueprint.json" <<'PY'
import json, sys
p = sys.argv[1]
bp = json.load(open(p))
bp["steps"].insert(0, {
    "step": "installPlugin",
    "pluginData": {"resource": "wordpress.org/plugins", "slug": "woocommerce"},
    "options": {"activate": True},
})
json.dump(bp, open(p, "w"), indent=2)
PY
fi

cleanup() { [ -n "${SRV:-}" ] && kill "$SRV" 2>/dev/null || true; }
trap cleanup EXIT

npx --yes @wp-playground/cli@latest server \
  --port="$PORT" --workers=1 \
  --blueprint="$WORK/blueprint.json" \
  --mount "$WORK/plugins:/wordpress/wp-content/plugins" > "$WORK/server.log" 2>&1 &
SRV=$!

echo -n "waiting for WordPress"
for _ in $(seq 1 180); do
  if curl -s --max-time 10 -X POST \
      "http://127.0.0.1:$PORT/?rest_route=/urumiai-store-for-ai/v1/rpc" \
      -H 'Content-Type: application/json' \
      -H 'Authorization: Bearer test-secret-token' \
      -d '{"jsonrpc":"2.0","id":1,"method":"ping"}' 2>/dev/null | grep -q '"result"'; then
    echo " ready"
    BASE_URL="http://127.0.0.1:$PORT" python3 "$HERE/integration-test.py"
    exit $?
  fi
  if ! kill -0 "$SRV" 2>/dev/null; then
    echo " server died:"; tail -5 "$WORK/server.log"; exit 1
  fi
  echo -n "."
  sleep 5
done

echo " timed out"; tail -10 "$WORK/server.log"; exit 1
