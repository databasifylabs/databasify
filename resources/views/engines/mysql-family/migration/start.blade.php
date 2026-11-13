@include('engines.mysql-family._mysql')
@include('engines.mysql-family._files')
databasify ctl stop "$MIGRATION_UNIT" > /dev/null 2>&1 || true
databasify ctl reset-failed "$MIGRATION_UNIT" > /dev/null 2>&1 || true
env_file="$ROOT/state/$MIGRATION_UNIT.env"
(
    umask 077
    for name in MIGRATION_HOST MIGRATION_PORT MIGRATION_USER MIGRATION_PASSWORD MIGRATION_TLS MIGRATION_DATABASES MIGRATION_CONTINUOUS; do
        printf 'export %s=%q\n' "$name" "${!name}"
    done > "$env_file"
)
databasify ctl run -u "$MIGRATION_UNIT" -r -- /bin/bash -c '. "$0" && rm -f "$0" && exec /usr/local/bin/databasify engine-task migration-copy' "$env_file" > /dev/null 2>&1 \
    || { rm -f "$env_file"; databasify_fail internal "could not start $MIGRATION_UNIT"; }
echo "Started the migration copy."
