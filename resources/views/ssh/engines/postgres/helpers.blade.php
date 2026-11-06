as_postgres() { setpriv --reuid=postgres --regid=postgres --init-groups env HOME=/var/lib/postgresql USER=postgres "$@"; }
pg() { as_postgres timeout 60 psql -X -A -t -v ON_ERROR_STOP=1 -h {{ $socketDirectory }} -p {{ $clientPort }} -d "${2:-postgres}" -c "$1" </dev/null 2>/dev/null; }
pg_from() { base64 -d | as_postgres timeout 300 psql -X -q -v ON_ERROR_STOP=1 -h {{ $socketDirectory }} -p {{ $clientPort }} -d postgres; }
patroni_ctl() { as_postgres patronictl -c {{ $directory }}/conf/patroni.yml "$@" </dev/null; }
patroni_state() { timeout 10 curl -s --max-time 5 http://127.0.0.1:{{ $patroniPort }}/patroni </dev/null 2>/dev/null | jq -r '"role:" + (.role // "unknown") + " state:" + (.state // "unknown")'; }
standalone_state() { role=$(pg "SELECT CASE WHEN pg_is_in_recovery() THEN 'replica' ELSE 'primary' END") && echo "role:$role state:running"; }
node_state() { if databasify ctl is-active --quiet databasify-patroni; then patroni_state; else standalone_state; fi; }
own_role() { node_state | sed -n 's/^role:\([a-z_]*\) .*/\1/p'; }
crash_looping() {
    restarts=$(databasify ctl show -p NRestarts --value "$1" 2>/dev/null || true)
    [ "${restarts:-0}" -gt "$2" ] 2>/dev/null || return 1
    databasify ctl logs --unit "$1" --lines 20
}
etcd_ctl() { timeout 30 etcdctl --endpoints=http://127.0.0.1:{{ $etcdClientPort }} "$@" </dev/null; }
other_etcd_endpoints() { { awk -F "'" '/^  hosts: / { print $2 }' {{ $directory }}/conf/patroni.yml 2>/dev/null || true; } | tr ',' '\n' | awk -v own="$1:{{ $etcdClientPort }}" 'NF && $0 != own { print "http://" $0 }'; }
answering_etcd() { for endpoint in $(other_etcd_endpoints "$1"); do if timeout 30 etcdctl --endpoints="$endpoint" member list </dev/null >/dev/null 2>&1; then echo "$endpoint"; return 0; fi; done; return 1; }
