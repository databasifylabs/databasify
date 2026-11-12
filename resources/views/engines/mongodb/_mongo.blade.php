@include('engines._runtime')
ROOT=/srv/mongodb

mongo_secret_of() {
    case "$1" in
        root | peers) trimmed_file "$ROOT/conf/admin.pw" ;;
        member) trimmed_file "$ROOT/conf/keyfile" ;;
        *) printf '' ;;
    esac
}

trimmed_file() {
    local content
    content=$(cat "$1" 2> /dev/null || true)
    content=${content#"${content%%[![:space:]]*}"}
    printf '%s' "${content%"${content##*[![:space:]]}"}"
}

mongo_prelude() {
    cat <<'JS'
@include('engines.mongodb._prelude')
JS
}

mongo_js() {
    local auth=$1 script errors output status secret marker
    script=$(mktemp)
    errors=$(mktemp)
    { mongo_prelude; cat; } > "$script"
    secret=${MONGO_SECRET-$(mongo_secret_of "$auth")}
    local target=("mongodb://${MONGO_HOST:-127.0.0.1:25060}/admin?directConnection=true&tls=${MONGO_TLS:-true}&tlsAllowInvalidCertificates=true&serverSelectionTimeoutMS=5000")
    if [ "$auth" = peers ]; then
        target=(--nodb)
    fi
    output=$(MONGO_AUTH="$auth" MONGO_SECRET="$secret" timeout "${MONGO_TIMEOUT:-100}" mongosh --quiet --norc "${target[@]}" --file "$script" 2> "$errors") && status=0 || status=$?
    rm -f "$script"
    if [ "$status" -eq 0 ]; then
        rm -f "$errors"
        printf '%s' "$output"
        return 0
    fi
    marker=$(sed -n '/^DATABASIFY_ERROR /,$p' <<< "$output")
    if [ -n "$marker" ]; then
        rm -f "$errors"
        databasify_fail "$(head -n 1 <<< "$marker" | sed 's/^DATABASIFY_ERROR //')" "$(tail -n +2 <<< "$marker")"
    fi
    mongo_failed "$status" "$errors"
}

mongo_failed() {
    local message
    message=$(tr -s '[:space:]' ' ' < "$2")
    rm -f "$2"
    if [ "$1" -eq 124 ]; then
        databasify_fail unavailable "mongosh timed out after ${MONGO_TIMEOUT:-100}s"
    fi
    case "$message" in
        *MongoNetworkError* | *MongoNetworkTimeoutError* | *ECONNREFUSED* | *'Server selection timed out'* | *MongoServerSelectionError* | *'connect ENOENT'*)
            databasify_fail unavailable "$message" ;;
    esac
    databasify_fail internal "mongosh exited $1: $message"
}

mongo_try() {
    local errors
    errors=$(mktemp)
    MONGO_ERROR=''
    MONGO_ERROR_TEXT=''
    MONGO_OUTPUT=$(mongo_js "$1" 3> "$errors") || {
        MONGO_ERROR=$(head -n 1 "$errors" | sed 's/^DATABASIFY_ERROR //')
        MONGO_ERROR_TEXT=$(tail -n +2 "$errors")
    }
    rm -f "$errors"
}
