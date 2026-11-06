@include('ssh.databases.helpers')
install -d -m 700 {{ $archiveWork }}/tmp
work=$(mktemp -d {{ $archiveWork }}/tmp/restore.XXXXXX)
trap 'rm -rf "$work"' EXIT
echo '{{ base64_encode($source) }}' | base64 -d > "$work/source.env"
set -a
. "$work/source.env"
set +a
sql() { timeout 600 {{ $client }} -N -B -e "$1" < /dev/null; }
up() { sql "SELECT 1" > /dev/null 2>&1; }

backup=$(rclone lsf -R --files-only "$ARCHIVE/basebackups/" | sed -n 's#^\([0-9]*\)/finished-\([0-9]*\)$#\1 \2#p' | awk '$2 <= {{ $targetEpoch }}' | sort -n -k 2 | tail -n 1)
if [ -z "$backup" ]; then
    echo "no base backup in the archive finished before {{ $target }} UTC"
    exit 1
fi
started=${backup%% *}
echo "restoring the base backup started at $(date -u -d "@$started" '+%Y-%m-%d %H:%M:%S') UTC"
databasify ctl stop {{ $engineService }} > /dev/null 2>&1 || true
find "$dir/data" -mindepth 1 -delete
rclone cat "$ARCHIVE/basebackups/$started/backup.xbstream.zst" | zstd -dq | {{ $streamTool }} -x -C "$dir/data"
if ! {{ $backupTool }} --prepare --use-memory=512M --target-dir="$dir/data" > "$work/prepare.log" 2>&1; then
    tail -n 20 "$work/prepare.log"
    echo "the base backup could not be prepared"
    exit 1
fi
cp "$dir/data/{{ $binlogInfo }}" "$work/binlog-info" 2> /dev/null || touch "$work/binlog-info"
rm -f "$dir/data/auto.cnf" "$dir/data/mysqld-auto.cnf"
chown -R mysql:mysql "$dir/data"
databasify ctl start {{ $engineService }}
wait_for 900 up

@include($archiveReplay)
before_replay
rclone lsf --files-only "$ARCHIVE/binlogs/" > "$work/listing" 2> "$work/listing.log" || [ $? -eq 3 ] || {
    tail -n 5 "$work/listing.log"
    echo "could not list the archived binlogs"
    exit 1
}
sort -t - -k 1,1n "$work/listing" > "$work/binlogs"
ceiling=$(awk -F - '$1 > {{ $targetEpoch }} { print $1; exit }' "$work/binlogs")
awk -F - -v from="$started" -v ceiling="${ceiling:-9999999999}" '$1 >= from && $1 <= ceiling' "$work/binlogs" > "$work/replay"
echo "replaying $(wc -l < "$work/replay") archived binlogs up to {{ $target }} UTC"
while read -r name; do
    rclone cat "$ARCHIVE/binlogs/$name" | zstd -dq > "$work/binlog"
    replay_binlog "$work/binlog"
done < "$work/replay"
after_replay
echo "restored to {{ $target }} UTC"
