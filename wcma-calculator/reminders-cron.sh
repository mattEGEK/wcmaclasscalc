#!/bin/sh
# wcma-calculator/reminders-cron.sh — the daily cron job: reminder emails, then the MotorsportReg calendar check. IONOS cron commands can only be a
# path (no spaces or options), so the cron job runs this file, which finds the PHP CLI and runs
# reminders.php. Output is appended to data/reminders.log (data/ is not served over the web).
# Set PHP_BIN to override the PHP binary.
cd "$(dirname "$0")" || exit 1
if [ -z "$PHP_BIN" ]; then
    for candidate in /usr/bin/php8.3-cli /usr/bin/php8.3 /usr/bin/php-cli /usr/bin/php php; do
        if command -v "$candidate" >/dev/null 2>&1; then PHP_BIN="$candidate"; break; fi
    done
fi
if [ -z "$PHP_BIN" ]; then echo "$(date) reminders: no PHP CLI found" >> data/reminders.log; exit 1; fi
"$PHP_BIN" reminders.php >> data/reminders.log 2>&1
# The MotorsportReg calendar check (msr-sync.php) runs even when the reminders fail, and vice versa.
"$PHP_BIN" msr-sync.php >> data/msr-sync.log 2>&1
