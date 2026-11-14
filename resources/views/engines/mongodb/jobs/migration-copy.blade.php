set -euo pipefail
@include('engines.mongodb._mongo')
@include('engines.mongodb._pbm')
COPY_DIR="$ROOT/migration"
PCSM=http://127.0.0.1:2242

if [ "$MIGRATION_CONTINUOUS" = true ]; then
    PCSM_SOURCE_URI="$SOURCE_URI" PCSM_TARGET_URI="$TARGET_URI" pcsm --log-no-color &
    pcsm_pid=$!
    stop_pcsm() {
        kill "$pcsm_pid" 2> /dev/null || true
        wait "$pcsm_pid" 2> /dev/null || true
    }
    deadline=$(( $(date +%s) + 60 ))
    until curl -fsS --max-time 5 "$PCSM/status" > /dev/null 2>&1; do
        if [ "$(date +%s)" -ge "$deadline" ]; then
            stop_pcsm
            fail_task 'ClusterSync did not answer within a minute'
        fi
        sleep 1
    done
    if ! reply=$(curl -fsS --max-time 30 -X POST -H 'Content-Type: application/json' -d "$PCSM_START" "$PCSM/start" 2>&1); then
        stop_pcsm
        fail_task "ClusterSync /start: $reply"
    fi
    verdict=$(json_eval "$reply" 'print(input && input.ok ? "ok" : String((input && input.error) || "no reply"))') || verdict='not JSON'
    if [ "$verdict" != ok ]; then
        stop_pcsm
        fail_task "ClusterSync /start: $verdict"
    fi
    wait "$pcsm_pid"
    exit $?
fi

rm -rf "$COPY_DIR"
mkdir -p -m 700 "$COPY_DIR"
trap 'rm -rf "$COPY_DIR"' EXIT
(
    umask 077
    printf '%s' "$SOURCE_TOOL_CONFIG" > "$COPY_DIR/source.yaml"
    printf '%s' "$TARGET_TOOL_CONFIG" > "$COPY_DIR/target.yaml"
)
IFS=$'\n' read -r -d '' -a databases <<< "$MIGRATION_DATABASES" || true
for database in "${databases[@]}"; do
    set +e
    mongodump --config="$COPY_DIR/source.yaml" --archive --gzip --db="$database" \
        | mongorestore --config="$COPY_DIR/target.yaml" --archive --gzip --nsInclude="$database.*" --drop --numInsertionWorkersPerCollection=4
    statuses=("${PIPESTATUS[@]}")
    set -e
    [ "${statuses[0]}" -eq 0 ] || fail_task "mongodump could not read $database from the source"
    [ "${statuses[1]}" -eq 0 ] || fail_task "mongorestore could not write $database"
done
