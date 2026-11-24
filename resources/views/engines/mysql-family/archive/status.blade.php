@include('engines.mysql-family._mysql')
@include('engines.mysql-family._rclone')
base=$(rclone_list base) || databasify_fail internal "$RCLONE_ERROR"
binlogs=$(TZ=UTC rclone_list binlogs -R --files-only) || binlogs='[]'
backup_result=$(databasify ctl show -p Result --value {!! $sh->arg($engine->archiveUnits()['backup']) !!} 2> /dev/null || true)
output=$(printf '%s\n%s\n' "$base" "$binlogs" | jq -cs --arg backupResult "$backup_result" '{base: .[0], latestBinlog: ([.[1][].ModTime] | max), backupResult: $backupResult}') || databasify_fail internal 'could not summarise the archive listing'
result "$output"
