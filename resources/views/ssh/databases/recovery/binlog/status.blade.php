state={{ $archiveWork }}/state
@include('ssh.databases.recovery.settings-check', ['path' => $archiveEnvironment])
set -a
. {{ $archiveEnvironment }}
set +a
errors=$(mktemp)
trap 'rm -f "$errors"' EXIT
listing=$(rclone lsf -R --files-only "$ARCHIVE/basebackups/" 2> "$errors") || [ $? -eq 3 ] || {
    echo "problem=Could not list the archive: $(tail -n 1 "$errors")"
    exit 0
}
finished=$(printf '%s\n' "$listing" | sed -n 's#^[0-9]*/finished-\([0-9]*\)$#\1#p' | sort -n)
backups=$(printf '%s\n' "$finished" | grep -c . || true)
earliest=$(printf '%s\n' "$finished" | head -n 1)
last_backup=$(printf '%s\n' "$finished" | tail -n 1)
latest=$(cat "$state/archived-through" 2> /dev/null || true)
if [ -z "$latest" ]; then
    latest=$({ rclone lsf --files-only "$ARCHIVE/binlogs/" 2> /dev/null || true; } | cut -d - -f 1 | sort -n | tail -n 1)
fi
problem=$(head -n 1 "$state/archive-problem" 2> /dev/null || head -n 1 "$state/backup-problem" 2> /dev/null || true)
@include('ssh.databases.recovery.report')
