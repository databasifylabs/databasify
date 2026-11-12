@include('engines._runtime')
MY_CLIENT={!! $sh->arg($engine->client) !!}
MY_CNF={!! $sh->arg($engine->adminCnf()) !!}

my() {
    local database=$1 errors output status
    errors=$(mktemp)
    output=$(timeout 60 "$MY_CLIENT" --defaults-extra-file="$MY_CNF" -N -B -r ${database:+"--database=$database"} 2> "$errors") && status=0 || status=$?
    if [ "$status" -ne 0 ]; then
        my_failed "$status" "$errors"
    fi
    rm -f "$errors"
    printf '%s' "$output"
}

my_failed() {
    local message
    message=$(cat "$2")
    rm -f "$2"
    if [ "$1" -eq 124 ]; then
        databasify_fail unavailable "$MY_CLIENT timed out after 60s"
    fi
    if [[ $message =~ ^ERROR\ ([0-9]+)\ \(([0-9A-Z]+)\)(\ at\ line\ [0-9]+)?:\ (.*)$ ]]; then
        my_server_failed "${BASH_REMATCH[1]}" "${BASH_REMATCH[2]}" "${BASH_REMATCH[4]}"
    fi
    databasify_fail internal "$MY_CLIENT exited $1: $message"
}

my_server_failed() {
    case "$1" in
        1290 | 1836) databasify_fail not_primary "$3" ;;
        1007 | 1396) databasify_fail already_exists "$3" ;;
        2002 | 2003 | 2006 | 2013) databasify_fail unavailable "$3" ;;
        *) databasify_fail internal "Error $1 ($2): $3" ;;
    esac
}

my_try() {
    local database=$1 errors
    errors=$(mktemp)
    MY_ERROR=''
    MY_ERROR_TEXT=''
    MY_OUTPUT=$(my "$database" 3> "$errors") || {
        MY_ERROR=$(head -n 1 "$errors" | sed 's/^DATABASIFY_ERROR //')
        MY_ERROR_TEXT=$(tail -n +2 "$errors")
    }
    rm -f "$errors"
}

my_string() {
    local value=$1 slash='\' quote="'" double='"' newline=$'\n' cr=$'\r' sub=$'\x1a'
    value=${value//"$slash"/"$slash$slash"}
    value=${value//"$quote"/"$slash$quote"}
    value=${value//"$double"/"$slash$double"}
    value=${value//"$newline"/"${slash}n"}
    value=${value//"$cr"/"${slash}r"}
    value=${value//"$sub"/"${slash}Z"}
    printf "'%s'" "$value"
}

my_name() {
    local value=$1 tick='`'
    value=${value//"$tick"/"$tick$tick"}
    printf '`%s`' "$value"
}
