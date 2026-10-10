#!/bin/bash
# Private one-shot only; installing this file does not schedule or execute it.
set -u
umask 077
if [[ $# != 1 || ! "$1" =~ ^[a-f0-9]{32}$ ]]; then
    printf '%s\n' 'One lowercase 32-hex audit nonce required.' >&2
    exit 2
fi
audit_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)" || exit 2
audit_run_report="$audit_root/storage/deploy/natural-audit-run-$1.json"
if [[ -e "$audit_run_report" || -L "$audit_run_report" ]]; then exit 0; fi
command -v timeout >/dev/null 2>&1 || exit 2
# Atomic permanent per-nonce lock/once marker. No worker lock or inherited FD.
mkdir "$audit_root/storage/deploy/natural-audit-$1.started" 2>/dev/null || exit 0
# Hard wall-clock bound includes every native guard, PDO operation and IPC read.
# SIGKILL at120s prevents any underlying stalled coroutine from surviving.
timeout --signal=KILL 120s /opt/cpanel/ea-php83/root/usr/bin/php \
    "$audit_root/deploy/natural-audit.php" "$1" >/dev/null 2>&1
audit_exit=$?
audit_complete=false
if [[ $audit_exit == 0 ]]; then audit_complete=true; fi
audit_timeout=false
if [[ $audit_exit == 124 || $audit_exit == 137 ]]; then audit_timeout=true; fi
set -o noclobber
printf '{"version":1,"nonce":"%s","exit_code":%d,"completed_successfully":%s,"outer_timeout_seconds":120,"timed_out":%s}\n' \
    "$1" "$audit_exit" "$audit_complete" "$audit_timeout" > "$audit_run_report" || exit 2
printf '%s\n' 'natural-audit runner finished; inspect both private reports.'
exit "$audit_exit"
