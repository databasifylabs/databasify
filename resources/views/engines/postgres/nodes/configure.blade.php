@include('engines.postgres._psql')
@include('engines.postgres._files')
ROOT=/srv/postgres
CONF=$ROOT/conf

ensure_directory "$CONF"
printf '%s' "$REQUEST_JSON" | write_file "$CONF/request.json" 600 || true
if [ -n "$TUNED_JSON" ] && [ ! -f "$CONF/tuned.json" ]; then
    printf '%s' "$TUNED_JSON" > "$CONF/tuned.json"
    chmod 600 "$CONF/tuned.json"
fi

changed=false
pool_size=$(default_pool_size)
while IFS=$'\t' read -r path mode content; do
    [ -z "$path" ] && continue
    if printf '%s' "$content" | base64 -d | sed "s/__DEFAULT_POOL_SIZE__/$pool_size/" | write_file "$path" "$mode"; then
        changed=true
    fi
done < <(jq -r '.[] | [.path, .mode, .content] | @tsv' <<< "$FILES")

if [ ! -f "$CONF/pgbouncer-pools.ini" ]; then
    pools_ini | write_file "$CONF/pgbouncer-pools.ini" 640 || true
fi
if [ "$CLUSTERED" = true ]; then
    rm -f "$CONF/postgresql.conf" "$CONF/pg_hba.conf"
fi
if [ "$ARCHIVE" != true ]; then
    rm -f "$CONF/pgbackrest.conf"
fi
if printf '%s' "$MANIFEST" | base64 -d | write_file "$CONF/processes.json" 640; then
    changed=true
    unit_do daemon-reload
fi

directories() {
    local mode=$1 name
    shift
    for name in "$@"; do
        mkdir -p -m "$mode" "$ROOT/$name"
        chown 64999:64999 "$ROOT/$name"
    done
}

etcd_ctl() {
    as_postgres timeout 30 etcdctl --endpoints="$1" --command-timeout=10s "${@:2}"
}

register_again() {
    local endpoint=$1 added
    added=$(etcd_ctl "$endpoint" member add "$IP" --peer-urls="http://$IP:2380" --learner 2>&1) || databasify_fail internal "etcdctl member: $added"
    printf '%s\n' "$(grep '^ETCD_' <<< "$added")" | write_file "$CONF/etcd-initial.env" 640 || true
}

repair_etcd_membership() {
    local endpoint='' ip voting members listed='' has_data=false removed
    while IFS=$'\t' read -r ip voting; do
        if [ "$voting" != true ] || [ "$ip" = "$IP" ]; then
            continue
        fi
        if etcd_ctl "http://$ip:2379" member list > /dev/null 2>&1; then
            endpoint="http://$ip:2379"
            break
        fi
    done < <(jq -r '.[] | [.ip, (.voting | tostring)] | @tsv' <<< "$MEMBERS")
    if [ -z "$endpoint" ]; then
        return 0
    fi
    members=$(etcd_ctl "$endpoint" member list 2> /dev/null) || return 0
    listed=$(awk -F ', ' -v peer="http://$IP:2380" 'NF >= 4 && $4 == peer { print $1; exit }' <<< "$members")
    if [ -e "$ROOT/etcd/member" ]; then
        has_data=true
    fi
    if [ -n "$listed" ] && [ "$has_data" = false ]; then
        removed=$(etcd_ctl "$endpoint" member remove "$listed" 2>&1) || databasify_fail internal "etcdctl member: $removed"
        register_again "$endpoint"
    elif [ -z "$listed" ] && [ "$has_data" = true ]; then
        unit_do stop databasify-etcd
        rm -rf "$ROOT/etcd/member"
        register_again "$endpoint"
    elif [ -z "$listed" ]; then
        register_again "$endpoint"
    fi
}

directories 700 data run
directories 750 archive archive/log archive/spool archive/lock archive/state
if [ "$CLUSTERED" != true ]; then
    if [ ! -s "$ROOT/data/PG_VERSION" ]; then
        output=$(as_postgres timeout 120 "/usr/lib/postgresql/$LINE/bin/initdb" -D "$ROOT/data" --encoding=UTF8 --data-checksums --auth-local=peer --auth-host=scram-sha-256 2>&1) \
            || databasify_fail internal "/usr/lib/postgresql/$LINE/bin/initdb -D: $(tail -n 5 <<< "$output")"
    fi
elif [ "$READ_ONLY" != true ]; then
    directories 700 etcd
    repair_etcd_membership
fi

result "$(jq -cn --argjson changed "$changed" '{changed: $changed}')"
