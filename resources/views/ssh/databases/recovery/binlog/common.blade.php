set -a
. {{ $archiveEnvironment }}
set +a
state={{ $archiveWork }}/state
install -d -m 700 "$state" {{ $archiveWork }}/tmp
sql() { timeout 60 {{ $client }} -N -B -e "$1" < /dev/null; }
fail() {
    echo "$1" > "$state/$problem"
    echo "$1" >&2
    exit 1
}
is_primary() { [ "$(sql 'SELECT @@@GLOBAL.read_only' 2> /dev/null)" = "0" ]; }
has_base_backup() {
    [ -f "$state/base-backup-seen" ] && return 0
    rclone lsf -R --files-only "$ARCHIVE/basebackups/" 2> /dev/null | grep -q '/finished-' || return 1
    touch "$state/base-backup-seen"
}
lower_bound() {
    if [ -s "$state/last-close" ]; then
        cat "$state/last-close"
        return 0
    fi
    newest=$(rclone lsf --files-only "$ARCHIVE/binlogs/" 2> /dev/null | cut -d - -f 1 | sort -n | tail -n 1)
    oldest_backup=$(rclone lsf --dirs-only "$ARCHIVE/basebackups/" 2> /dev/null | tr -d / | sort -n | head -n 1)
    echo "${newest:-${oldest_backup:-0}}"
}
