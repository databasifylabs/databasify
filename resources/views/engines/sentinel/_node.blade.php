@include('engines._write')
CONF="$ROOT/conf/$FLAVOR.conf"

until_ok() {
    local seconds=$1 label=$2
    shift 2
    wait_until "$seconds" 2 "$@" || fail_task "$FLAVOR did not become ready within $label"
}

conf_text() {
    cat "$CONF" 2> /dev/null || true
}

write_conf() {
    printf '%s' "$1" | write_file "$CONF" 600 true || true
}

replica_of() {
    sed -n 's/^replicaof \([^ ]*\) [0-9]*$/\1/p' "$CONF" 2> /dev/null | head -n 1
}

set_replica_of() {
    local text
    text=$(grep -v '^replicaof [^ ]* [0-9]*$' "$CONF" 2> /dev/null || true)
    text="${text%$'\n'}"$'\n'
    if [ -n "$1" ]; then
        text+="replicaof $1 6379"$'\n'
    fi
    write_conf "$text"
}

holding() {
    grep -qx 'replica-priority 0' "$CONF" 2> /dev/null
}

hold() {
    if ! holding; then
        local text
        text=$(cat "$CONF"; printf x)
        text=${text%x}
        write_conf "${text}replica-priority 0"$'\n'
    fi
}

release() {
    local_cli CONFIG SET replica-priority 100 > /dev/null
    local text
    text=$(grep -vx 'replica-priority 0' "$CONF" || true)
    write_conf "${text}"$'\n'
}

has_data() {
    [ -s "$ROOT/data/dump.rdb" ] || [ -n "$(ls -A "$ROOT/data/appendonlydir" 2> /dev/null)" ]
}

restart_loses_data() {
    [ "$1" != true ] || ! has_data
}

replication_info() {
    cli_try local_cli INFO replication
    [ -z "$CLI_ERROR" ] && printf '%s' "$CLI_OUTPUT"
}

current_role() {
    local info
    info=$(replication_info) || return 0
    info_field "$info" role
}

replica_synced() {
    local info
    info=$(replication_info) || return 1
    [ "$(info_field "$info" master_link_status)" = up ] && [ "$(info_field "$info" master_sync_in_progress)" = 0 ]
}

aof_written() {
    local info
    cli_try local_cli INFO persistence
    [ -z "$CLI_ERROR" ] || return 1
    info=$CLI_OUTPUT
    [ "$(info_field "$info" aof_rewrite_in_progress)" = 0 ] && [ "$(info_field "$info" aof_rewrite_scheduled)" = 0 ]
}

sentinel_primary() {
    cli_try sentinel_cli SENTINEL get-master-addr-by-name main
    [ -z "$CLI_ERROR" ] && head -n 1 <<< "$CLI_OUTPUT"
}

sentinel_answers() {
    cli_try sentinel_cli PING
    [ "$CLI_OUTPUT" = PONG ]
}

serves_as_primary() {
    [ -n "$1" ] || return 1
    cli_try cli "$1" 6379 ROLE
    [ -z "$CLI_ERROR" ] && [ "$(head -n 1 <<< "$CLI_OUTPUT")" = master ]
}

pair_value() {
    awk -v key="$2" 'NR % 2 == 1 && $0 == key { getline; print; exit }' <<< "$1"
}

sentinel_hosts() {
    printf '127.0.0.1\n'
    sed -n 's/^sentinel known-sentinel main \([^ ]*\) .*/\1/p' "$ROOT/sentinel/sentinel.conf" 2> /dev/null | awk '!seen[$0]++'
}

newest_primary() {
    local host view epoch ip best='' best_epoch=-1
    while IFS= read -r host; do
        [ -z "$host" ] && continue
        SENTINEL_HOST=$host cli_try sentinel_cli SENTINEL MASTER main
        [ -n "$CLI_ERROR" ] && continue
        view=$CLI_OUTPUT
        epoch=$(pair_value "$view" config-epoch)
        ip=$(pair_value "$view" ip)
        if [[ $epoch =~ ^[0-9]+$ ]] && [ -n "$ip" ] && [ "$epoch" -gt "$best_epoch" ]; then
            best=$ip
            best_epoch=$epoch
        fi
    done < <(sentinel_hosts | awk '!seen[$0]++')
    if serves_as_primary "$best"; then
        NEWEST_PRIMARY=$best
        NEWEST_EPOCH=$best_epoch
    else
        NEWEST_PRIMARY=''
        NEWEST_EPOCH=-1
    fi
}

require_replicas() {
    local_cli CONFIG SET min-replicas-to-write "$1" > /dev/null
    write_conf "$(sed "s/^min-replicas-to-write [0-9]*$/min-replicas-to-write $1/" "$CONF")"$'\n'
}

daemon_reload() {
    printf '{}' | databasify service reload > /dev/null
}
