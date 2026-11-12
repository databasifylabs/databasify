@include('engines.mysql-family._restore')

later_positions() {
    tr ',' '\n' <<< "$1,$2" | awk -F '-' '
        { gsub(/[[:space:]]/, "") }
        NF == 3 {
            if (!($1 in best)) { order[++count] = $1; best[$1] = $0; sequence[$1] = $3 + 0 }
            else if ($3 + 0 > sequence[$1]) { best[$1] = $0; sequence[$1] = $3 + 0 }
        }
        END { for (i = 1; i <= count; i++) printf "%s%s", (i > 1 ? "," : ""), best[order[i]] }'
}

start=$gtids
while IFS= read -r host; do
    [ -z "$host" ] && continue
    files=()
    while IFS= read -r file; do
        files+=("$file")
    done < <(find "$host" -maxdepth 1 -type f | LC_ALL=C sort)
    output=$( { TZ=UTC mariadb-binlog --skip-gtid-strict-mode --start-position="$start" --stop-datetime="$TARGET" "${files[@]}" | mariadb --defaults-extra-file="$ROOT/conf/admin.cnf"; } 2>&1) \
        || fail_task "replaying binary logs: $(tail -n 3 <<< "$output" | tr '\n' ' ')"
    replayed=$(my '' <<'SQL'
@verbatim
SELECT @@gtid_binlog_pos
@endverbatim
SQL
)
    start=$(later_positions "$start" "$replayed")
done < <(for host in "$RESTORE"/binlogs/*/; do
    first=$(find "$host" -maxdepth 1 -type f 2> /dev/null | LC_ALL=C sort | head -n 1)
    [ -n "$first" ] && printf '%s %s\n' "$(stat -c %Y "$first")" "$host"
done | sort -n | cut -d ' ' -f 2-)
rm -rf "$RESTORE"
