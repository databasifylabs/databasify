@include('ssh.databases.helpers')
for process in $processes; do
    databasify ctl start "$process" || echo "$process did not start, recovering without it"
done
