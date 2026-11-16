@include('engines.mysql-family._mysql')
@include('engines.mysql-family._rclone')
base=$(rclone_list base) || databasify_fail internal "$RCLONE_ERROR"
binlogs=$(rclone_list binlogs -R) || binlogs='[]'
backup_result=$(databasify ctl show -p Result --value {!! $sh->arg($engine->archiveUnits()['backup']) !!} 2> /dev/null || true)
result "$(jq -cn --argjson base "$base" --argjson binlogs "$binlogs" --arg backupResult "$backup_result" '{base: $base, binlogs: $binlogs, backupResult: $backupResult}')"
