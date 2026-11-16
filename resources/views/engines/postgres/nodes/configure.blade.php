@include('engines.postgres._psql')
@include('engines.postgres._files')
@include('engines.postgres._cluster')
ROOT=/srv/postgres
CONF=$ROOT/conf

ensure_directory "$CONF"
printf '%s' {!! $sh->arg($requestJson) !!} | write_file "$CONF/request.json" 600 || true
@if ($tunedJson !== null)
if [ ! -f "$CONF/tuned.json" ]; then
    printf '%s' {!! $sh->arg($tunedJson) !!} | write_file "$CONF/tuned.json" 600 || true
fi
@endif

changed=false
@foreach ($files->files as $file)
if printf '%s' {!! $sh->arg($file->content) !!} | write_file {!! $sh->arg($file->path) !!} {{ decoct($file->mode) }}; then
    changed=true
fi
@endforeach
if [ ! -f "$CONF/pgbouncer-pools.ini" ]; then
    : | write_file "$CONF/pgbouncer-pools.ini" 640 || true
fi
@if ($config->clustered)
rm -f "$CONF/postgresql.conf" "$CONF/pg_hba.conf"
@endif
@if ($config->archive === null)
rm -f "$CONF/pgbackrest.conf"
@endif
if printf '%s' {!! $sh->arg($files->manifest) !!} | write_file "$CONF/processes.json" 640; then
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

directories 700 data run
directories 750 archive archive/log archive/spool archive/lock archive/state
@if (! $config->clustered)
if [ ! -s "$ROOT/data/PG_VERSION" ]; then
    output=$(as_postgres timeout 120 {!! $sh->arg("/usr/lib/postgresql/{$line}/bin/initdb") !!} -D "$ROOT/data" --encoding=UTF8 --data-checksums --auth-local=peer --auth-host=scram-sha-256 2>&1) \
        || databasify_fail internal {!! $sh->arg("/usr/lib/postgresql/{$line}/bin/initdb -D: ") !!}"$(tail -n 5 <<< "$output")"
fi
@elseif (! $config->readOnly)
SELF={!! $sh->arg($config->ip) !!}

register_again() {
    local endpoint=$1 added
    added=$(etcd_ctl "$endpoint" member add "$SELF" --peer-urls="http://$SELF:2380" --learner 2>&1) || databasify_fail internal "etcdctl member: $added"
    printf '%s\n' "$(grep '^ETCD_' <<< "$added")" | write_file "$CONF/etcd-initial.env" 640 || true
}

repair_etcd_membership() {
    local endpoint='' ip members listed='' has_data=false removed
    for ip in {!! collect($votingPeers)->map($sh->arg(...))->implode(' ') !!}; do
        if etcd_ctl "http://$ip:2379" member list > /dev/null 2>&1; then
            endpoint="http://$ip:2379"
            break
        fi
    done
    if [ -z "$endpoint" ]; then
        return 0
    fi
    members=$(etcd_ctl "$endpoint" member list 2> /dev/null) || return 0
    listed=$(awk -F ', ' -v peer="http://$SELF:2380" 'NF >= 4 && $4 == peer { print $1; exit }' <<< "$members")
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

directories 700 etcd
repair_etcd_membership
@endif

[ "$changed" = true ] && echo "Wrote the new configuration." || echo "The configuration was already up to date."
