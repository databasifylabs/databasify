@include('ssh.databases.helpers')
for process in $processes; do
    databasify ctl enable "$process" > /dev/null 2>&1
    databasify ctl restart "$process"
done
for process in $processes; do
    printf '%s: %s\n' "$process" "$(databasify ctl is-active "$process" || true)"
done
