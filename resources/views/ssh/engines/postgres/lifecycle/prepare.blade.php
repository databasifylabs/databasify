@include('ssh.databases.helpers')
@include('ssh.engines.postgres.helpers')
install -d -m 700 -o postgres -g postgres "$dir/data" "$dir/run"
install -d -m 750 -o postgres -g postgres "$dir/archive" "$dir/archive/log"
@if (! $clustered)
if [ ! -s "$dir/data/PG_VERSION" ]; then
    as_postgres /usr/lib/postgresql/{{ $line }}/bin/initdb -D "$dir/data" --encoding=UTF8 --data-checksums --auth-local=peer --auth-host=scram-sha-256 > /dev/null
    echo "initialised PostgreSQL {{ $line }} in $dir/data"
fi
@elseif (! $readOnly)
install -d -m 700 -o postgres -g postgres "$dir/etcd"
peer_url=http://{{ $privateIp }}:{{ $etcdPeerPort }}
endpoint=$(answering_etcd {{ $privateIp }} || true)
other_etcd() { timeout 30 etcdctl --endpoints="$endpoint" "$@" < /dev/null; }
members=""
[ -z "$endpoint" ] || members=$(other_etcd member list || true)
register_again() { other_etcd member add {{ $privateIp }} --peer-urls="$peer_url" --learner | grep '^ETCD_' | put_file "$dir/conf/etcd-initial.env" 640 postgres; }
listed=$(printf '%s\n' "$members" | awk -F ', ' -v peer="$peer_url" '$4 == peer { print $1 }')
if [ -z "$members" ]; then
    true
elif [ -n "$listed" ] && [ ! -d "$dir/etcd/member" ]; then
    other_etcd member remove "$listed" > /dev/null
    register_again
    echo "etcd on {{ $privateIp }} lost its data, registered it again with the other members as a learner"
@if ($fresh)
elif [ -z "$listed" ] && [ -d "$dir/etcd/member" ]; then
@else
elif [ -z "$listed" ]; then
@endif
    databasify ctl stop databasify-etcd
    rm -rf "$dir/etcd/member"
    register_again
    echo "etcd on {{ $privateIp }} was dropped from the cluster while it was down, registered it again with the other members as a learner"
fi
@endif
