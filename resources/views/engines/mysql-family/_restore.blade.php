@include('engines.mysql-family._mysql')
@include('engines.mysql-family._files')
@include('engines.mysql-family._server')
@include('engines.mysql-family._rclone')
RESTORE="$ROOT/restore"

piped() {
    local label=$1 output
    shift
    output=$("$@" 2>&1) || fail_task "$label: $(tail -n 3 <<< "$output" | tr '\n' ' ')"
}

phase 'downloading'
entries=$(rclone_list base) || fail_task "$RCLONE_ERROR"
chosen=$(jq -r --arg suffix "$SUFFIX" --arg target "$TARGET_STAMP" '[.[].Path] as $paths | [$paths[] | select(endswith($suffix)) | rtrimstr($suffix) | select(. as $name | $paths | index($name + ".binlog-info")) | select(test("^[0-9]{8}T[0-9]{6}Z$")) | select(. <= $target)] | sort | last // empty' <<< "$entries")
if [ -z "$chosen" ]; then
    fail_task "no base backup was taken before $TARGET_RFC3339"
fi
rm -rf "$RESTORE"
mkdir -p -m 700 "$RESTORE/data" "$RESTORE/binlogs"
@if ($engine->id === 'mysql')
rclone cat "$(remote_of "base/$chosen$SUFFIX")" | /usr/bin/xbstream -x -C "$RESTORE/data" || fail_task 'xbstream could not unpack the base backup'
@else
rclone cat "$(remote_of "base/$chosen$SUFFIX")" | /usr/bin/mbstream -x -C "$RESTORE/data" || fail_task 'mbstream could not unpack the base backup'
@endif
info=$(rclone cat "$(remote_of "base/$chosen.binlog-info")") || fail_task 'rclone cat: could not read the backup position'
piped 'rclone copy' rclone copy "$(remote_of binlogs)" "$RESTORE/binlogs"
gtids=$(printf '%s' "$info" | tr -d '\n' | sed -n "s/.*GTID of the last change '\([^']*\)'.*/\1/p")
if [ -z "$gtids" ]; then
    fail_task 'the base backup records no GTID position'
fi

phase 'preparing'
@if ($engine->id === 'mysql')
piped /usr/bin/xtrabackup /usr/bin/xtrabackup --prepare "--target-dir=$RESTORE/data"
@else
piped /usr/bin/mariadb-backup /usr/bin/mariadb-backup --prepare "--target-dir=$RESTORE/data"
phase 'starting'
@endif

unit_do stop "$SERVER_UNIT"
rm -rf "$ROOT/data"
engine_directory data
@if ($engine->id === 'mysql')
piped /usr/bin/xtrabackup /usr/bin/xtrabackup "--defaults-file=$ROOT/conf/agent.cnf" --move-back "--target-dir=$RESTORE/data"
@else
piped /usr/bin/mariadb-backup /usr/bin/mariadb-backup --move-back "--datadir=$ROOT/data" "--target-dir=$RESTORE/data"
@endif
chown -R 64999:64999 "$ROOT/data"
@if ($engine->id === 'mariadb')
rm -f "$ROOT/state/archive-id"
@endif
unit_do start "$SERVER_UNIT"
wait_until 21600 2 serving || fail_task 'the server was not ready within 6h0m0s'

phase 'replaying'
