@include('engines.mysql-family._mysql')
@include('engines.mysql-family._files')
databasify ctl stop "$MIGRATION_UNIT" > /dev/null 2>&1 || true
databasify ctl reset-failed "$MIGRATION_UNIT" > /dev/null 2>&1 || true
env_file="$ROOT/state/$MIGRATION_UNIT.env"
job_file="$ROOT/state/$MIGRATION_UNIT.sh"
(
    umask 077
    for name in MIGRATION_HOST MIGRATION_PORT MIGRATION_USER MIGRATION_PASSWORD MIGRATION_TLS MIGRATION_DATABASES MIGRATION_CONTINUOUS MYSQLSH_URI; do
        printf 'export %s=%q\n' "$name" "${!name}"
    done > "$env_file"
    printf '%s' "$COPY_SCRIPT" | base64 -d > "$job_file"
)
databasify ctl run -u "$MIGRATION_UNIT" -r -- /bin/bash -c '. "$0" && rm -f "$0" && exec /bin/bash "$1"' "$env_file" "$job_file" > /dev/null 2>&1 \
    || { rm -f "$env_file"; databasify_fail internal "could not start $MIGRATION_UNIT"; }
echo 'Started the migration copy.'
