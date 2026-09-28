#!/usr/bin/env bash
# wcma-calculator/tests/ux/run-audit.sh — phone audit (mobile UX spec 2026-09-28 §B4).
# Seeds a throwaway database, serves the app, runs audit.mjs, cleans up.
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
APP="$(cd "$HERE/../.." && pwd)"
PORT="${UX_PORT:-8170}"
TMP="$(mktemp -d)"
TMPW="$(cygpath -m "$TMP" 2>/dev/null || echo "$TMP")"   # PHP on Windows needs C:/… paths
cat > "$TMP/prepend.php" <<EOF
<?php
if (!defined('DB_PATH')) define('DB_PATH', '$TMPW/ux.db');
if (!defined('WCMA_MAIL_LOG')) define('WCMA_MAIL_LOG', '$TMPW/mail.log');
EOF
php -d auto_prepend_file="$TMPW/prepend.php" "$APP/seed-hub-db.php" > /dev/null
( cd "$APP" && exec php -d auto_prepend_file="$TMPW/prepend.php" -S "localhost:$PORT" > "$TMP/server.log" 2>&1 ) &
SERVER=$!
trap 'kill $SERVER 2>/dev/null || true; rm -rf "$TMP"' EXIT
for _ in 1 2 3 4 5 6 7 8 9 10; do curl -s -o /dev/null "http://localhost:$PORT/index.php" && break; sleep 0.5; done
UX_BASE="http://localhost:$PORT" node "$HERE/audit.mjs"
