#!/bin/bash
set -u
umask 077
appdir=/home/dwhwlekshosting/telegram-mirror-thucunghaiza
nonce=${1:-}
[[ "$nonce" =~ ^[a-f0-9]{32}$ ]] || exit 2
mkdir "$appdir/storage/deploy/repost-audit-$nonce.started" 2>/dev/null || exit 0
/usr/bin/timeout --signal=KILL 120s /opt/cpanel/ea-php83/root/usr/bin/php "$appdir/deploy/repost-audit.php" "$nonce" >/dev/null 2>&1
result=$?
timed_out=false
[[ "$result" == 124 || "$result" == 137 ]] && timed_out=true
successful=false
[[ "$result" == 0 ]] && successful=true
( set -C; printf '{"nonce":"%s","exit_code":%s,"completed_successfully":%s,"outer_timeout_seconds":120,"timed_out":%s}\n' "$nonce" "$result" "$successful" "$timed_out" > "$appdir/storage/deploy/repost-audit-$nonce.run.json" )
exit "$result"
