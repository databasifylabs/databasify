@include('ssh.engines.mysql.helpers')
@include('ssh.databases.helpers')
restarts() { databasify ctl show -p NRestarts --value mysql 2> /dev/null || echo 0; }
restarts_before=$(restarts)
crashed() { databasify ctl is-failed --quiet mysql || [ "$(restarts)" -ge $((restarts_before + 2)) ]; }
offline_checks=0
outside_the_group() {
@if ($clustered && ! $readOnly)
    case "$(msql "SELECT MEMBER_STATE FROM performance_schema.replication_group_members WHERE MEMBER_ID IN (@@@server_uuid, '')" || echo UNKNOWN)" in
        OFFLINE|ERROR|'') ;;
        *) offline_checks=0; return 1 ;;
    esac
    offline_checks=$((offline_checks + 1))
    [ "$offline_checks" -ge 100 ]
@else
    return 1
@endif
}
online() {
    if crashed; then
        databasify ctl logs -u mysql -n 20
        echo "mysql stopped while starting"
        exit 1
    fi
    if outside_the_group; then
        databasify ctl logs -u mysql -n 500 | grep '\[Repl\]' | grep -E '\[(ERROR|Warning)\]' | tail -5 || true
        echo "mysql runs but stayed outside the group"
        exit 1
    fi
    own_state | grep -q 'ONLINE'
}
client_up() { ss -ltn | grep -q ':{{ $clientPort }} '; }
wait_for {{ $deadline }} online
wait_for 300 client_up
own_state | tr '\t' ' '
