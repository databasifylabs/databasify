source_cnf_of() {
    local tls=$5
    printf '[client]\nhost=%s\nport=%s\nuser=%s\npassword=%s\n' "$1" "$2" "$(option_value "$3")" "$(option_value "$4")"
    if [ "$tls" = true ]; then
        printf 'ssl\nskip-ssl-verify-server-cert\n'
    else
        printf 'skip-ssl\n'
    fi
}

copy_from() {
    local host=$1 port=$2 user=$3 password=$4 tls=$5 gtid=$6 errors loaded statuses found
    shift 6
    source_cnf_of "$host" "$port" "$user" "$password" "$tls" | write_file "$ROOT/conf/source.cnf" 600 false || true
    local args=("--defaults-extra-file=$ROOT/conf/source.cnf" --single-transaction --routines --events --triggers)
    if [ "$gtid" = true ]; then
        args+=(--gtid --master-data=2)
    fi
    if [ "$#" -eq 0 ]; then
        args+=(--all-databases)
    else
        args+=(--databases "$@")
    fi
    errors=$(mktemp)
    loaded=$(mktemp)
    found=$(mktemp)
    set +o pipefail
    mariadb-dump "${args[@]}" 2> "$errors" | tee >(grep -ao "gtid_slave_pos='[^']*'" > "$found") | mariadb --defaults-extra-file="$MY_CNF" 2> "$loaded"
    statuses=("${PIPESTATUS[@]}")
    set -o pipefail
    wait $! 2> /dev/null || true
    rm -f "$ROOT/conf/source.cnf"
    COPIED_GTID=''
    if [ "${statuses[0]}" -ne 0 ]; then
        ATTEMPT_ERROR="mariadb-dump: exit status ${statuses[0]}: $(tr -s '[:space:]' ' ' < "$errors")"
    elif [ "${statuses[2]}" -ne 0 ]; then
        ATTEMPT_ERROR="mariadb: exit status ${statuses[2]}: $(tr -s '[:space:]' ' ' < "$loaded")"
    else
        ATTEMPT_ERROR=''
        COPIED_GTID=$(head -n 1 "$found" | sed -n "s/^gtid_slave_pos='\(.*\)'$/\1/p")
    fi
    rm -f "$errors" "$loaded" "$found"
    [ -z "$ATTEMPT_ERROR" ]
}
