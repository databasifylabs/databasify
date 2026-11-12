@include('engines.mysql-family._mysql')
@include('engines.mysql-family._rclone')
base=$(rclone_list base) || databasify_fail internal "$RCLONE_ERROR"
shipped='[]'
if sources=$(rclone_list binlogs); then
    while IFS= read -r source; do
        [ -z "$source" ] && continue
        files=$(rclone_list "binlogs/$(base64 -d <<< "$source")") || continue
        shipped=$(jq -c --argjson files "$files" '. + ($files | map(.ModTime))' <<< "$shipped")
    done < <(jq -r '.[].Path | @base64' <<< "$sources")
fi
backup_result=$(databasify ctl show -p Result --value "$BACKUP_UNIT" 2> /dev/null || true)
bases=$(jq --arg suffix "$SUFFIX" '[.[].Path] as $paths | [$paths[] | select(endswith($suffix)) | rtrimstr($suffix) | select(. as $name | $paths | index($name + ".binlog-info")) | select(test("^[0-9]{8}T[0-9]{6}Z$"))] | length' <<< "$base")
failed=false
if [ -n "$backup_result" ] && [ "$backup_result" != success ]; then
    failed=true
fi
if [ "$BACK_UP_NOW" = true ] || { [ "$bases" = 0 ] && [ "$failed" = false ]; }; then
    databasify ctl start --no-block "$BACKUP_UNIT" > /dev/null 2>&1 || true
fi
result "$(jq -cn --argjson base "$base" --argjson shipped "$shipped" --arg result "$backup_result" '{base: $base, shipped: $shipped, backupResult: $result}')"
