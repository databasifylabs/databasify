@include('engines.mysql-family._mysql')
@include('engines.mysql-family._rclone')
base=$(rclone_list base) || databasify_fail internal "$RCLONE_ERROR"
binlogs=$(rclone_list binlogs -R) || binlogs='[]'
backup_result=$(databasify ctl show -p Result --value {!! $sh->arg($engine->archiveUnits()['backup']) !!} 2> /dev/null || true)
result "$(printf '%s\n%s\n' "$base" "$binlogs" | jq -cs --arg backupResult "$backup_result" '{base: .[0], binlogs: .[1], backupResult: $backupResult}')"
