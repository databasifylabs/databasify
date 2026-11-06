@include('ssh.engines.postgres.helpers')
@include('ssh.databases.helpers')
databasify ctl disable --now databasify-patroni databasify-etcd > /dev/null 2>&1 || true
rm -rf "$dir/etcd" "$dir/conf/patroni.yml" "$dir/conf/etcd.env" "$dir/conf/etcd-initial.env" "$dir/conf/etcd-force.env" "$dir/conf/pgpass"
rm -f "$dir/data/standby.signal" "$dir/data/patroni.dynamic.json"
databasify ctl daemon-reload
databasify ctl reset-failed databasify-postgres > /dev/null 2>&1 || true
databasify ctl enable --now databasify-postgres > /dev/null
databasify ctl restart databasify-pgbouncer
serving() { [ "$(own_role)" = "primary" ]; }
wait_for 300 serving
pg "SELECT count(pg_drop_replication_slot(slot_name)) FROM pg_replication_slots WHERE slot_type = 'physical' AND NOT active" | sed 's/$/ replication slots of former members dropped/'
echo "postgres runs on its own on this node"
