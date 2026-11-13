@include('engines.mongodb._mongo')
databasify ctl stop mongodb-migration > /dev/null 2>&1 || true
databasify ctl reset-failed mongodb-migration > /dev/null 2>&1 || true
env_file="$ROOT/state/mongodb-migration.env"
job_file="$ROOT/state/mongodb-migration.sh"
(
    umask 077
    for name in SOURCE_URI TARGET_URI SOURCE_TOOL_CONFIG TARGET_TOOL_CONFIG PCSM_START MIGRATION_DATABASES MIGRATION_CONTINUOUS; do
        printf 'export %s=%q\n' "$name" "${!name}"
    done > "$env_file"
    printf '%s' "$COPY_SCRIPT" | base64 -d > "$job_file"
)
databasify ctl run -u mongodb-migration -r -- /bin/bash -c '. "$0" && rm -f "$0" && exec /bin/bash "$1"' "$env_file" "$job_file" > /dev/null 2>&1 \
    || { rm -f "$env_file"; databasify_fail internal 'could not start mongodb-migration'; }
echo 'Started the migration copy.'
