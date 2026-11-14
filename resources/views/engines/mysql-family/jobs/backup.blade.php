set -euo pipefail
@include('engines.mysql-family._mysql')
@include('engines.mysql-family._files')
@include('engines.mysql-family._rclone')
@include('engines.mysql-family._writer')
BASE_SUFFIX={!! $sh->arg($engine->baseSuffix()) !!}
BACKUP_TMP="$ROOT/backup/tmp"
BACKUP_META="$ROOT/backup/meta"

is_writer || exit 0
rm -rf "$BACKUP_TMP" "$BACKUP_META"
mkdir -p -m 700 "$BACKUP_TMP" "$BACKUP_META"
stamp=$(date -u +%Y%m%dT%H%M%SZ)
@if ($engine->id === 'mysql')
xtrabackup --defaults-file="$ROOT/conf/agent.cnf" --backup --stream=xbstream --target-dir="$BACKUP_TMP" --extra-lsndir="$BACKUP_META" \
    | rclone rcat "$(remote_of "base/$stamp$BASE_SUFFIX")"
info="$BACKUP_META/xtrabackup_info"
@else
galera=()
if [ "${BACKUP_GALERA_INFO:-false}" = true ]; then
    galera=(--galera-info)
fi
mariadb-backup --defaults-file="$ROOT/conf/agent.cnf" --backup --stream=mbstream --target-dir="$BACKUP_TMP" --extra-lsndir="$BACKUP_META" ${galera[@]+"${galera[@]}"} \
    | rclone rcat "$(remote_of "base/$stamp$BASE_SUFFIX")"
if [ ! -f "$BACKUP_META/mariadb_backup_info" ]; then
    cp "$BACKUP_META/xtrabackup_info" "$BACKUP_META/mariadb_backup_info"
fi
info="$BACKUP_META/mariadb_backup_info"
@endif
rclone copyto "$info" "$(remote_of "base/$stamp.binlog-info")"

listing=$(rclone_list base) || fail_task "$RCLONE_ERROR"
@verbatim
expired=$(jq -r --arg suffix "$BASE_SUFFIX" --argjson days "$RETENTION_DAYS" --argjson now "$(date +%s)" '
    [.[].Path] as $paths
    | [.[].Path
        | select(endswith($suffix))
        | rtrimstr($suffix) as $name
        | select(($paths | index($name + ".binlog-info")) != null)
        | {name: $name, at: (try ($name | strptime("%Y%m%dT%H%M%SZ") | mktime) catch null)}
        | select(.at != null)]
    | sort_by(.at) as $bases
    | ([range(0; $bases | length) | select($bases[.].at < ($now - $days * 86400))] | last // 0) as $keep
    | $bases[:$keep][]
    | .name' <<< "$listing")
@endverbatim
for name in $expired; do
    rclone deletefile "$(remote_of "base/$name$BASE_SUFFIX")"
    rclone deletefile "$(remote_of "base/$name.binlog-info")"
done
