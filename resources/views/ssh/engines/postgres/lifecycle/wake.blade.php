@include('ssh.databases.helpers')
@if ($clustered)
databasify ctl start databasify-etcd
echo "etcd started, Patroni stays stopped until the most advanced member is chosen"
@else
for process in $processes; do
    databasify ctl start "$process"
done
@endif
