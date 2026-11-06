set -euo pipefail
problem=backup-problem
@include('ssh.databases.recovery.binlog.common')
is_primary || { echo "not the primary, skipping"; exit 0; }

started=$(date +%s)
target="$ARCHIVE/basebackups/$started"
work=$(mktemp -d {{ $archiveWork }}/tmp/backup.XXXXXX)
trap 'rm -rf "$work"' EXIT
mkdir -p "$work/stream"

if ! { {{ $backupTool }} --backup --stream=xbstream --target-dir="$work/stream" 2> "$work/backup.log" \
    | zstd -q -T0 -3 \
    | rclone rcat --s3-chunk-size 128M --s3-upload-concurrency 2 "$target/backup.xbstream.zst"; } \
    || ! tail -n 1 "$work/backup.log" | grep -q 'completed OK!'; then
    rclone purge "$target" 2> /dev/null || true
    tail -n 5 "$work/backup.log" >&2 || true
    fail "The base backup started at $(date -u -d "@$started" '+%Y-%m-%d %H:%M') UTC failed."
fi

finished=$(date +%s)
printf '%s\n' "$finished" | rclone rcat "$target/finished-$finished" || fail "Could not mark the base backup started at $started as finished."
echo "uploaded the base backup started at $(date -u -d "@$started" '+%Y-%m-%d %H:%M:%S') UTC"
touch "$state/base-backup-seen"

cutoff=$(( finished - RETENTION_DAYS * 86400 ))
listing=$(rclone lsf -R --files-only "$ARCHIVE/basebackups/") || fail "Could not list the base backups to apply retention."
complete=$(printf '%s\n' "$listing" | sed -n 's#^\([0-9]*\)/finished-\([0-9]*\)$#\1 \2#p' | sort -n -k 2)
kept=$({ printf '%s\n' "$complete" | awk -v cutoff="$cutoff" 'NF && $2 < cutoff' | tail -n 1; printf '%s\n' "$complete" | awk -v cutoff="$cutoff" 'NF && $2 >= cutoff'; } | awk '{ print $1 }' | sort -n)
oldest_kept=$(printf '%s\n' "$kept" | head -n 1)

for start in $(printf '%s\n' "$listing" | cut -d / -f 1 | sort -un); do
    [ "$start" -lt "$oldest_kept" ] || continue
    rclone purge "$ARCHIVE/basebackups/$start" || fail "Could not remove the expired base backup started at $start."
    echo "removed the expired base backup started at $(date -u -d "@$start" '+%Y-%m-%d %H:%M:%S') UTC"
done

{ rclone lsf --files-only "$ARCHIVE/binlogs/" 2> /dev/null || true; } | awk -F - -v before=$(( oldest_kept - 3600 )) '$1 < before' > "$work/expired"
if [ -s "$work/expired" ]; then
    rclone delete "$ARCHIVE/binlogs/" --files-from "$work/expired" || fail "Could not remove expired binlogs."
    echo "removed $(wc -l < "$work/expired") expired binlogs"
fi
rm -f "$state/$problem"
