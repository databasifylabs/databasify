@include('ssh.engines.postgres.helpers')
@include('ssh.databases.helpers')
@foreach ($files as $file)
@include('ssh.databases.files.put', ['owner' => $file->owner])
@endforeach
@if ($clustered)
as_postgres python3 - "$dir/conf/patroni.yml" > "$dir/conf/dcs.yml" <<'PY'
import sys
import yaml

with open(sys.argv[1]) as config:
    dcs = yaml.safe_load(config)['bootstrap']['dcs']

print(yaml.safe_dump(dcs))
PY
standby_limits=$(as_postgres python3 - "$dir/conf/dcs.yml" <<'PY'
import sys
import yaml

with open(sys.argv[1]) as dcs:
    parameters = yaml.safe_load(dcs)['postgresql']['parameters']

for name in ('max_connections', 'max_locks_per_transaction', 'max_prepared_transactions', 'max_wal_senders', 'max_worker_processes'):
    print(name, parameters.get(name, ''))
PY
)
patroni_ctl edit-config --force --replace "$dir/conf/dcs.yml" > /dev/null
rm -f "$dir/conf/dcs.yml"
echo "cluster-wide settings stored in etcd"
lowers_standby_limits() {
    while read -r name wanted; do
        [ -n "$wanted" ] || wanted=$(pg "SELECT boot_val FROM pg_settings WHERE name = '$name'")
        if [ "$wanted" -lt "$(pg "SHOW $name")" ]; then
            return 0
        fi
    done <<< "$standby_limits"
    return 1
}
restart_pending() { timeout 10 curl -s --max-time 5 http://127.0.0.1:{{ $patroniPort }}/patroni | jq -e '.pending_restart == true' > /dev/null; }
leading() { patroni_state | grep -q '^role:primary state:running'; }
if lowers_standby_limits && wait_for 60 restart_pending; then
    patroni_ctl restart {{ $patroniScope }} --role primary --force > /dev/null
    wait_for 300 leading
    echo "restarted the primary first, standbys cannot start below its connection, lock and worker limits"
fi
@endif
