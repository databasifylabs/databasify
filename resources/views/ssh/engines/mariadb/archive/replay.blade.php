position=""
merge_positions() {
    printf '%s,%s\n' "$1" "$2" | tr ',' '\n' | awk -F - 'NF == 3 && (!($1 in latest) || $3 + 0 > seq[$1]) { latest[$1] = $0; seq[$1] = $3 + 0 } END { for (domain in latest) { printf "%s%s", separator, latest[domain]; separator = "," } }'
}
gtids_in() {
    {{ $binlogTool }} "$@" 2> /dev/null | awk -v only_list="${only_list:-0}" '
        /^#/ && /Gtid list \[/ { listing = 1; sub(/.*Gtid list \[/, "") }
        listing { sub(/^# */, ""); closed = sub(/\].*/, ""); printf "%s,", $0; if (closed) { listing = 0; if (only_list) exit } next }
        /^#/ && match($0, /GTID [0-9]+-[0-9]+-[0-9]+/) { printf "%s,", substr($0, RSTART + 5, RLENGTH - 5) }
    ' || true
}
gtid_state_at() { merge_positions "" "$(gtids_in --stop-position="$2" "$1")"; }
gtid_list_of() { merge_positions "" "$(only_list=1 gtids_in "$1")"; }
archived_copies_of() {
    { rclone lsf --files-only "$ARCHIVE/binlogs/" 2> /dev/null || true; } \
        | awk -F - -v from="$started" -v suffix="-$1.zst" '$1 >= from && substr($0, length($0) - length(suffix) + 1) == suffix' \
        | sort -t - -k 1,1n
}
gtids_missing_from() { tr ',' '\n' <<< "$position" | sed '/^$/d' | grep -vxF -f <(tr ',' '\n' <<< "$1") || true; }
written_by_backup() {
    backup_state=$(gtid_state_at "$1" "$backup_offset")
    if [ -z "$position" ]; then
        [ -z "$backup_state" ]
        return
    fi
    [ -z "$(gtids_missing_from "$backup_state")" ]
}
before_replay() {
    read -r backup_file backup_offset position < "$work/binlog-info" || true
    [ -n "${backup_file:-}" ] || return 0
    for copy in $(archived_copies_of "$backup_file"); do
        rclone cat "$ARCHIVE/binlogs/$copy" | zstd -dq > "$work/backup-binlog"
        written_by_backup "$work/backup-binlog" || continue
        position=$(merge_positions "$position" "$backup_state")
        echo "replaying from the gtid state of the base backup in $copy"
        return 0
    done
    echo "No archived copy of $backup_file matches the base backup (${position:-no gtid}); restore to the backup point instead, or check the archive."
    exit 1
}
replay_binlog() {
    position=$(merge_positions "$(gtid_list_of "$1")" "$position")
    TZ=UTC {{ $binlogTool }} --skip-gtid-strict-mode --stop-datetime='{{ $target }}' ${position:+--start-position="$position"} "$1" | timeout 21600 {{ $client }} --binary-mode
    position=$(merge_positions "$position" "$(sql "{!! $gtidQuery !!}")")
}
after_replay() {
    sql "STOP ALL SLAVES; RESET SLAVE ALL" > /dev/null 2>&1 || true
}
