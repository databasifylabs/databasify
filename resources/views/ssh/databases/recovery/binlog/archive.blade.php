set -euo pipefail
problem=archive-problem
@include('ssh.databases.recovery.binlog.common')

if ! is_primary; then
    rm -f "$state/gtid" "$state/last-close" "$state/archived-through" "$state/archive-problem"
    exit 0
fi

has_base_backup || exit 0
server_id=$(sql 'SELECT @@@GLOBAL.server_id')

if [ ! -f "$state/uploaded" ]; then
    { rclone lsf --files-only "$ARCHIVE/binlogs/" 2> /dev/null || true; } | sed -n "s/^\([0-9]*\)-$server_id-\(.*\)\.zst$/\1-\2/p" > "$state/uploaded"
fi

checked_at=$(date +%s)
gtid=$(sql "{!! $gtidQuery !!}") || fail "Could not read the executed transactions."
if [ "$gtid" != "$(cat "$state/gtid" 2> /dev/null || true)" ]; then
    sql "FLUSH BINARY LOGS" > /dev/null || fail "Could not rotate the binlog."
fi

lower=$(( $(lower_bound) - 120 ))
basename=$(sql 'SELECT @@@GLOBAL.log_bin_basename') || fail "Could not find the binlog directory."
binlog_dir=$(dirname "$basename")
names=$(sql "SHOW BINARY LOGS" | awk '{ print $1 }') || fail "Could not list the binlogs."
for file in $(printf '%s\n' "$names" | head -n -1); do
    path="$binlog_dir/$file"
    [ -f "$path" ] || continue
    closed=$(stat -c %Y "$path")
    [ "$closed" -ge "$lower" ] || continue
    grep -Fqx "$closed-$file" "$state/uploaded" && continue
    zstd -q -c "$path" | rclone rcat "$ARCHIVE/binlogs/$closed-$server_id-$file.zst" || fail "Could not upload the binlog $file."
    echo "$closed-$file" >> "$state/uploaded"
    echo "$closed" > "$state/last-close"
done

awk 'NR == FNR { present[$0] = 1; next } { file = $0; sub(/^[0-9]*-/, "", file) } file in present' <(printf '%s\n' "$names") "$state/uploaded" > "$state/uploaded.kept" || true
mv "$state/uploaded.kept" "$state/uploaded"
echo "$gtid" > "$state/gtid"
echo "$checked_at" > "$state/archived-through"
rm -f "$state/archive-problem"
