@include('engines.mysql-family._restore')
for host in "$RESTORE"/binlogs/*/; do
    [ -d "$host" ] || continue
    files=()
    while IFS= read -r file; do
        files+=("$file")
    done < <(find "$host" -maxdepth 1 -type f | LC_ALL=C sort)
    [ "${#files[@]}" -gt 0 ] || continue
    output=$( { TZ=UTC mysqlbinlog --exclude-gtids="$gtids" --stop-datetime="$TARGET" "${files[@]}" | mysql --defaults-extra-file="$ROOT/conf/admin.cnf"; } 2>&1) \
        || fail_task "replaying $(basename "$host"): $(tail -n 3 <<< "$output" | tr '\n' ' ')"
done
rm -rf "$RESTORE"
