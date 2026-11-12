LOCAL_ETCD=http://127.0.0.1:2379

as_postgres_run() {
    HOME=/var/lib/postgresql setpriv --reuid=64999 --regid=64999 --clear-groups "$@"
}

etcd_ctl() {
    as_postgres_run timeout 30 etcdctl --endpoints="$1" --command-timeout=10s "${@:2}"
}

patroni_value() {
    awk -v path="$1" '
        /^[[:space:]]*#/ || /^[[:space:]]*-/ { next }
        {
            line = $0
            match(line, /^ */)
            indent = RLENGTH
            trimmed = substr(line, indent + 1)
            colon = index(trimmed, ":")
            if (colon == 0) { next }
            key = substr(trimmed, 1, colon - 1)
            value = substr(trimmed, colon + 1)
            while (depth > 0 && indents[depth] >= indent) { depth-- }
            depth++
            keys[depth] = key
            indents[depth] = indent
            joined = keys[1]
            for (i = 2; i <= depth; i++) { joined = joined "." keys[i] }
            if (joined == path) {
                sub(/^[[:space:]]+/, "", value)
                if (substr(value, 1, 1) == "\047") {
                    value = substr(value, 2)
                    end = index(value, "\047")
                    if (end > 0) { value = substr(value, 1, end - 1) }
                } else {
                    sub(/[[:space:]]*#.*$/, "", value)
                    sub(/[[:space:]]+$/, "", value)
                }
                print value
                exit
            }
        }' /srv/postgres/conf/patroni.yml 2> /dev/null
}

own_ip() {
    patroni_value name
}

peer_etcd_endpoints() {
    local self=$1 host
    for host in $(patroni_value etcd3.hosts | tr ',' ' '); do
        if [ -n "$host" ] && [ "${host#"$self":}" = "$host" ]; then
            echo "http://$host"
        fi
    done
}

member_with_peer() {
    etcd_ctl "$1" member list --consistency=s 2> /dev/null | awk -F ', ' -v peer="http://$2:2380" 'NF >= 6 && $4 == peer { print $1, $6; exit }'
}

promoted_through() {
    local member id learner
    member=$(member_with_peer "$1" "$2") || return 1
    [ -z "$member" ] && return 1
    read -r id learner <<< "$member"
    if [ "$learner" != true ]; then
        return 0
    fi
    etcd_ctl "$1" member promote "$id" > /dev/null 2>&1
}

promote_own_learner() {
    local ip endpoint
    ip=$(own_ip)
    promoted() {
        for endpoint in "$LOCAL_ETCD" $(peer_etcd_endpoints "$ip"); do
            if promoted_through "$endpoint" "$ip"; then
                return 0
            fi
        done
        return 1
    }
    wait_until 300 3 promoted || true
}

etcd_healthy() {
    etcd_ctl "$LOCAL_ETCD" endpoint health > /dev/null 2>&1
}

restarts_of() {
    local count
    count=$(databasify ctl show -p NRestarts --value "$1" 2> /dev/null || true)
    echo "${count:-0}"
}

patroni_role() {
    case "$(patroni_state | jq -r '.role // ""')" in
        master | primary) echo primary ;;
        replica | standby_leader) echo replica ;;
        *) echo unknown ;;
    esac
}

patroni_running_state() {
    patroni_state | jq -r '.state // ""'
}

settled() {
    local role state
    role=$(patroni_role)
    state=$(patroni_running_state)
    { [ "$state" = running ] || [ "$state" = streaming ]; } && { [ "$role" = primary ] || [ "$role" = replica ]; }
}

main_unit() {
    if clustered; then
        echo databasify-patroni
    else
        echo databasify-postgres
    fi
}
