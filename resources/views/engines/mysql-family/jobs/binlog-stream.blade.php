set -uo pipefail
@include('engines.mysql-family._mysql')
@include('engines.mysql-family._files')
@include('engines.mysql-family._writer')
BINLOGS="$ROOT/archive/binlogs"
NOT_WRITER_WAIT=30
RETRY_WAIT=10
child=''
trap '[ -z "$child" ] || kill "$child" 2> /dev/null; exit 0' TERM INT

pause() {
    sleep "$1" &
    child=$!
    wait "$child"
    child=''
}

while true; do
    if ! is_writer; then
        pause "$NOT_WRITER_WAIT"
        continue
    fi
    mkdir -p -m 700 "$BINLOGS"
    my_try '' <<< 'SHOW BINARY LOGS'
    on_server=$(cut -f 1 <<< "$MY_OUTPUT")
    if [ -n "$MY_ERROR" ] || [ -z "$on_server" ]; then
        printf 'binary log copy stopped: %s\n' "${MY_ERROR_TEXT:-the server has no binary logs}" >&2
        pause "$RETRY_WAIT"
        continue
    fi
    last=''
    for file in "$BINLOGS"/*; do
        if [ -f "$file" ] && [[ $file != *.tmp ]] && [[ ${file##*/} > $last ]]; then
            last=${file##*/}
        fi
    done
    first=$(head -n 1 <<< "$on_server")
    if [ -n "$last" ] && grep -qxF -- "$last" <<< "$on_server"; then
        first=$last
    fi
@if ($engine->id === 'mysql')
    mysqlbinlog --defaults-extra-file="$ROOT/conf/agent.cnf" --read-from-remote-server --raw --stop-never \
        --connection-server-id="$STREAM_SERVER_ID" --result-file="$BINLOGS/" "$first" &
@else
    mariadb-binlog --defaults-extra-file="$ROOT/conf/agent.cnf" --read-from-remote-server --raw --stop-never \
        --stop-never-slave-server-id="$STREAM_SERVER_ID" --result-file="$BINLOGS/" "$first" &
@endif
    child=$!
    wait "$child" || printf 'binary log copy stopped: exit status %s\n' "$?" >&2
    child=''
    pause "$RETRY_WAIT"
done
