@include('engines.mongodb._mongo')
databasify ctl stop mongodb-migration > /dev/null 2>&1 || true
databasify ctl reset-failed mongodb-migration > /dev/null 2>&1 || true
env_file="$ROOT/state/mongodb-migration.env"
(
    umask 077
    for name in MIGRATION_HOST MIGRATION_PORT MIGRATION_USER MIGRATION_PASSWORD MIGRATION_TLS MIGRATION_DATABASES MIGRATION_CONTINUOUS; do
        printf 'export %s=%q\n' "$name" "${!name}"
    done > "$env_file"
)
databasify ctl run -u mongodb-migration -r -- /bin/bash -c '. "$0" && rm -f "$0" && exec /usr/local/bin/databasify engine-task migration-copy' "$env_file" > /dev/null 2>&1 \
    || { rm -f "$env_file"; databasify_fail internal 'could not start mongodb-migration'; }
result '{}'
