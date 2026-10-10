#!/bin/bash
set -u
umask 077
appdir=/home/dwhwlekshosting/telegram-mirror-thucunghaiza
phpcli=/opt/cpanel/ea-php83/root/usr/bin/php
"$phpcli" "$appdir/bin/worker.php" >> "$appdir/storage/worker.log" 2>&1 &
workerpid=$!
report_status() {
    reportfile="$appdir/storage/deploy/live-status.$$.tmp"
    "$phpcli" "$appdir/bin/status.php" > "$reportfile" 2>/dev/null
    if test $? -eq 0; then
        mv "$reportfile" "$appdir/storage/deploy/live-status.json"
    fi
}
while kill -0 "$workerpid" 2>/dev/null; do
    report_status
    sleep 5
done
wait "$workerpid"
report_status
