@include('engines.postgres._psql')
STATE=/srv/postgres/migration/state
databasify ctl stop databasify-postgres-migration > /dev/null 2>&1 || true
databasify ctl reset-failed databasify-postgres-migration > /dev/null 2>&1 || true
rm -rf "$STATE"
mkdir -p -m 700 "$STATE"
chown 64999:64999 "$STATE" /srv/postgres/migration
umask 077
{
    for name in SOURCE_HOST SOURCE_PORT SOURCE_USER SOURCE_PASSWORD SOURCE_SSLMODE MIGRATION_DATABASES MIGRATION_OWNER MIGRATION_CONTINUOUS MIGRATION_PUBLICATION; do
        printf '%s=%q\n' "$name" "${!name}"
    done
} > "$STATE/copy.env"
printf '%s' "$COPY_SCRIPT" | base64 -d > "$STATE/copy.sh"
databasify ctl run -u databasify-postgres-migration -r bash "$STATE/copy.sh" > /dev/null 2>&1 \
    || databasify_fail internal 'could not start databasify-postgres-migration'
echo "Started the migration copy."
