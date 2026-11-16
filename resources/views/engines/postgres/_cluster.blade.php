LOCAL_ETCD=http://127.0.0.1:2379

etcd_ctl() {
    as_postgres timeout 30 etcdctl --endpoints="$1" --command-timeout=10s "${@:2}"
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

promote_learner() {
    local ip=$1 endpoint
    shift
    promoted() {
        for endpoint in "$@"; do
            if promoted_through "$endpoint" "$ip"; then
                return 0
            fi
        done
        return 1
    }
    wait_until 300 3 promoted "$@"
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
