#!/bin/bash
set -u
umask 077
appdir=/home/dwhwlekshosting/telegram-mirror-thucunghaiza
phpcli=/opt/cpanel/ea-php83/root/usr/bin/php
# Cron starts a bounded observer. Its lock is explicitly closed in children,
# so a surviving worker cannot retain the watchdog lock after this run exits.
exec 9>"$appdir/storage/supervisor-watch.lock"
/usr/bin/flock -n 9 || exit 0
report_status() {
    reportfile="$appdir/storage/deploy/live-status.$$.tmp"
    /usr/bin/timeout --signal=KILL 10s "$phpcli" "$appdir/bin/status.php" > "$reportfile" 2>/dev/null 9>&-
    if test $? -eq 0; then
        mv "$reportfile" "$appdir/storage/deploy/live-status.json"
    else
        rm -f "$reportfile"
    fi
}
end=$((SECONDS + 50))
while test "$SECONDS" -lt "$end"; do
    /usr/bin/timeout --signal=KILL 40s "$phpcli" "$appdir/bin/supervise.php" >/dev/null 2>&1 9>&-
    report_status
    sleep 5
done
report_status
