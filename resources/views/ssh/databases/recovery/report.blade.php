result=$(databasify ctl show -p Result --value {{ $backupService }} 2> /dev/null || true)
backup_failed=0
if [ -n "$result" ] && [ "$result" != "success" ]; then
    backup_failed=1
    [ -n "$problem" ] || problem="The last base backup stopped with $result, see the {{ $backupService }} log."
fi
if [ -n "$last_backup" ] && { [ -z "$latest" ] || [ "$latest" -lt "$last_backup" ]; }; then
    latest=$last_backup
fi
@if ($backUpNow)
start_backup=1
@else
start_backup=0
[ "$backups" -gt 0 ] || [ "$backup_failed" = "1" ] || start_backup=1
@endif
if [ "$start_backup" = "1" ] && [ "$(databasify ctl is-active {{ $backupService }} 2> /dev/null || true)" != "activating" ]; then
    databasify ctl start --no-block {{ $backupService }}
fi
echo "backups=$backups"
echo "earliest=$earliest"
echo "latest=$latest"
echo "last_backup=$last_backup"
echo "problem=$problem"
