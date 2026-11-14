@include('engines.postgres._psql')
for unit in databasify-patroni databasify-etcd; do
    databasify ctl disable "$unit" > /dev/null 2>&1 || true
    databasify ctl stop "$unit" > /dev/null 2>&1 || true
done
for name in etcd conf/patroni.yml conf/etcd.env conf/etcd-initial.env conf/etcd-force.env conf/dcs.yml conf/pgpass data/standby.signal data/patroni.dynamic.json; do
    rm -rf "/srv/postgres/$name"
done
echo "Stopped the cluster services."
