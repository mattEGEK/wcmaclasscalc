#!/usr/bin/env bash
# wcma-calculator/tests/ux/run-audit.sh — phone audit (mobile UX spec 2026-09-28 §B4).
# Seeds a throwaway database, serves the app, runs audit.mjs, cleans up.
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
APP="$(cd "$HERE/../.." && pwd)"
PORT="${UX_PORT:-8170}"
URL="http://localhost:$PORT/index.php"
SERVER=""
TMP="$(mktemp -d)"
trap '[ -n "$SERVER" ] && kill $SERVER 2>/dev/null; rm -rf "$TMP"' EXIT

if [ ! -d "$HERE/node_modules/playwright" ]; then
  echo "Playwright is not installed: run 'npm install' in wcma-calculator/tests/ux first." >&2; exit 2
fi
# Something already answering on the port would be audited instead of this app.
if curl -s -o /dev/null "$URL"; then
  echo "Port $PORT is already in use: stop that server or set UX_PORT." >&2; exit 2
fi

TMPW="$(cygpath -m "$TMP" 2>/dev/null || echo "$TMP")"   # PHP on Windows needs C:/… paths
cat > "$TMP/prepend.php" <<EOF
<?php
if (!defined('DB_PATH')) define('DB_PATH', '$TMPW/ux.db');
if (!defined('WCMA_MAIL_LOG')) define('WCMA_MAIL_LOG', '$TMPW/mail.log');
EOF
php -d auto_prepend_file="$TMPW/prepend.php" "$APP/seed-hub-db.php" > /dev/null
( cd "$APP" && exec php -d auto_prepend_file="$TMPW/prepend.php" -S "localhost:$PORT" > "$TMP/server.log" 2>&1 ) &
SERVER=$!
for _ in $(seq 1 20); do curl -s -o /dev/null "$URL" && break; sleep 0.5; done
if ! kill -0 "$SERVER" 2>/dev/null || ! curl -s -o /dev/null "$URL"; then
  echo "The app server could not start on port $PORT:" >&2; cat "$TMP/server.log" >&2; exit 2
fi
UX_BASE="http://localhost:$PORT" node "$HERE/audit.mjs"
