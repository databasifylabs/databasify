@include('ssh.engines.mariadb.migrations.env')
databasify ctl stop mariadb-migration > /dev/null 2>&1 || true
databasify ctl reset-failed mariadb-migration > /dev/null 2>&1 || true
rm -rf "$state"
install -d -m 700 "$state"
cat > "$state/copy.sh" <<'COPY'
set -eu
source_args="-h $SOURCE_HOST -P $SOURCE_PORT -u $SOURCE_USER $SOURCE_SSL"
databases="$MIGRATION_DATABASES"
if [ -z "$databases" ]; then
    listed=$(mariadb $source_args -N -B -e "SELECT schema_name FROM information_schema.schemata WHERE schema_name NOT IN ('mysql', 'sys', 'performance_schema', 'information_schema') ORDER BY schema_name")
    databases=$(printf '%s\n' "$listed" | { grep -Ex "$SCHEMA_NAME" || true; } | paste -sd ' ' -)
    skipped=$(printf '%s\n' "$listed" | { grep -Evx "$SCHEMA_NAME" || true; } | paste -sd ' ' -)
    [ -z "$skipped" ] || echo "skipping $skipped: Databasify copies only databases whose names start with a letter and contain only letters, digits and underscores"
fi
echo "copying $databases"
mariadb-dump $source_args --single-transaction --master-data=2 --routines --triggers --events --databases $databases > "$STATE_DIR/dump.sql"
grep -m 1 'CHANGE MASTER TO' "$STATE_DIR/dump.sql" | sed -n "s/.*MASTER_LOG_FILE='\([^']*\)', MASTER_LOG_POS=\([0-9]*\).*/\1 \2/p" > "$STATE_DIR/position"
mariadb < "$STATE_DIR/dump.sql"
rm -f "$STATE_DIR/dump.sql"
echo "copied every database"
COPY
databasify ctl run --quiet --collect --unit=mariadb-migration \
    --setenv=SOURCE_HOST="$SOURCE_HOST" --setenv=SOURCE_PORT="$SOURCE_PORT" --setenv=SOURCE_USER="$SOURCE_USER" --setenv=MYSQL_PWD="$MYSQL_PWD" --setenv=SOURCE_SSL="$SOURCE_SSL" \
    --setenv=STATE_DIR="$state" --setenv=MIGRATION_DATABASES='{{ $databases }}' --setenv=SCHEMA_NAME='{!! $schemaName !!}' \
    sh -c "sh $state/copy.sh > $state/copy.log 2>&1; echo \$? > $state/copy.exit"
echo "copying from {{ $address }} in the background"
