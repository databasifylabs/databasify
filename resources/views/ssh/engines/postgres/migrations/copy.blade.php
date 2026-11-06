@include('ssh.engines.postgres.migrations.env')
databasify ctl stop databasify-postgres-migration > /dev/null 2>&1 || true
databasify ctl reset-failed databasify-postgres-migration > /dev/null 2>&1 || true
rm -rf "$state"
install -d -m 700 -o postgres -g postgres "$state"
setpriv --reuid=postgres --regid=postgres --init-groups sh -c "cat > $state/copy.sh" <<'COPY'
set -eu
source="host=$SOURCE_HOST port=$SOURCE_PORT user=$SOURCE_USER sslmode=$SOURCE_SSLMODE connect_timeout=10"
own_restored() {
    psql -X -U postgres -d "$1" -v ON_ERROR_STOP=1 -q -v owner="$MIGRATION_OWNER" <<'SQL'
WITH restored AS (
    SELECT n.oid, n.nspname, n.nspowner FROM pg_namespace n WHERE n.nspname <> 'information_schema' AND n.nspname NOT LIKE 'pg\_%'
), members AS (
    SELECT d.classid, d.objid FROM pg_depend d WHERE d.deptype = 'e'
)
SELECT format('ALTER SCHEMA %I OWNER TO %I', n.nspname, :'owner')
FROM restored n
WHERE n.nspowner = 'postgres'::regrole AND n.nspname <> 'public'
    AND n.oid NOT IN (SELECT objid FROM members WHERE classid = 'pg_namespace'::regclass)
UNION ALL
SELECT format('ALTER %s %s OWNER TO %I', CASE c.relkind WHEN 'v' THEN 'VIEW' WHEN 'm' THEN 'MATERIALIZED VIEW' WHEN 'f' THEN 'FOREIGN TABLE' WHEN 'S' THEN 'SEQUENCE' WHEN 'c' THEN 'TYPE' ELSE 'TABLE' END, c.oid::regclass, :'owner')
FROM pg_class c JOIN restored n ON n.oid = c.relnamespace
WHERE c.relowner = 'postgres'::regrole AND c.relkind IN ('r', 'p', 'v', 'm', 'f', 'S', 'c')
    AND c.oid NOT IN (SELECT objid FROM members WHERE classid = 'pg_class'::regclass)
    AND NOT (c.relkind = 'S' AND EXISTS (SELECT 1 FROM pg_depend d WHERE d.classid = 'pg_class'::regclass AND d.objid = c.oid AND d.refclassid = 'pg_class'::regclass AND d.deptype IN ('a', 'i')))
UNION ALL
SELECT format('ALTER ROUTINE %s OWNER TO %I', p.oid::regprocedure, :'owner')
FROM pg_proc p JOIN restored n ON n.oid = p.pronamespace
WHERE p.proowner = 'postgres'::regrole AND p.oid NOT IN (SELECT objid FROM members WHERE classid = 'pg_proc'::regclass)
UNION ALL
SELECT format('ALTER %s %s OWNER TO %I', CASE t.typtype WHEN 'd' THEN 'DOMAIN' ELSE 'TYPE' END, t.oid::regtype, :'owner')
FROM pg_type t JOIN restored n ON n.oid = t.typnamespace
WHERE t.typowner = 'postgres'::regrole AND t.typtype IN ('d', 'e', 'r') AND t.oid NOT IN (SELECT objid FROM members WHERE classid = 'pg_type'::regclass)
UNION ALL
SELECT format('ALTER COLLATION %s OWNER TO %I', c.oid::regcollation, :'owner')
FROM pg_collation c JOIN restored n ON n.oid = c.collnamespace
WHERE c.collowner = 'postgres'::regrole AND c.oid NOT IN (SELECT objid FROM members WHERE classid = 'pg_collation'::regclass)
UNION ALL
SELECT format('ALTER OPERATOR %s OWNER TO %I', o.oid::regoperator, :'owner')
FROM pg_operator o JOIN restored n ON n.oid = o.oprnamespace
WHERE o.oprowner = 'postgres'::regrole AND o.oid NOT IN (SELECT objid FROM members WHERE classid = 'pg_operator'::regclass)
UNION ALL
SELECT format('ALTER TEXT SEARCH CONFIGURATION %s OWNER TO %I', c.oid::regconfig, :'owner')
FROM pg_ts_config c JOIN restored n ON n.oid = c.cfgnamespace
WHERE c.cfgowner = 'postgres'::regrole AND c.oid NOT IN (SELECT objid FROM members WHERE classid = 'pg_ts_config'::regclass)
UNION ALL
SELECT format('ALTER TEXT SEARCH DICTIONARY %s OWNER TO %I', d.oid::regdictionary, :'owner')
FROM pg_ts_dict d JOIN restored n ON n.oid = d.dictnamespace
WHERE d.dictowner = 'postgres'::regrole AND d.oid NOT IN (SELECT objid FROM members WHERE classid = 'pg_ts_dict'::regclass)
UNION ALL
SELECT format('ALTER LARGE OBJECT %s OWNER TO %I', l.oid, :'owner')
FROM pg_largeobject_metadata l
WHERE l.lomowner = 'postgres'::regrole
\gexec
SQL
}
quoted() { printf "'%s'" "$(printf '%s' "$1" | sed "s/\\\\/\\\\\\\\/g; s/'/\\\\'/g")"; }
databases="$MIGRATION_DATABASES"
if [ -z "$databases" ]; then
    listed=$(psql "$source dbname=postgres" -XAtc "SELECT datname FROM pg_database WHERE NOT datistemplate AND datname <> 'postgres' ORDER BY datname")
    databases=$(printf '%s\n' "$listed" | { grep -Ex "$SCHEMA_NAME" || true; } | paste -sd ' ' -)
    skipped=$(printf '%s\n' "$listed" | { grep -Evx "$SCHEMA_NAME" || true; } | paste -sd ' ' -)
    [ -z "$skipped" ] || echo "skipping $skipped: Databasify copies only databases whose names start with a letter and contain only letters, digits and underscores"
fi
for db in $databases; do
    echo "copying $db"
    psql -X -U postgres -d postgres -Atc "SELECT 1 FROM pg_database WHERE datname = '$db'" | grep -q 1 || psql -X -U postgres -d postgres -qc "CREATE DATABASE \"$db\""
    psql -X -U postgres -d postgres -qc "GRANT ALL PRIVILEGES ON DATABASE \"$db\" TO \"$MIGRATION_OWNER\""
    psql -X -U postgres -d "$db" -qc "GRANT ALL ON SCHEMA public TO \"$MIGRATION_OWNER\""
    if [ "$MIGRATION_CONTINUOUS" = "true" ]; then
        pg_dump "$source dbname=$db" --schema-only --no-owner --no-acl -Fc | pg_restore -U postgres -d "$db" --no-owner --no-acl --exit-on-error
        own_restored "$db"
        tables=$(psql "$source dbname=$db" -XAtc "SELECT string_agg(format('%I.%I', schemaname, tablename), ', ') FROM pg_tables WHERE schemaname NOT IN ('pg_catalog', 'information_schema')")
        [ -n "$tables" ] || { echo "$db has no tables to replicate"; continue; }
        psql "$source dbname=$db" -Xqc "DROP PUBLICATION IF EXISTS \"$MIGRATION_PUBLICATION\"" -c "CREATE PUBLICATION \"$MIGRATION_PUBLICATION\" FOR TABLE $tables"
        conninfo="host=$SOURCE_HOST port=$SOURCE_PORT user=$SOURCE_USER password=$(quoted "$PGPASSWORD") dbname=$db sslmode=$SOURCE_SSLMODE"
        psql -X -U postgres -d "$db" -v ON_ERROR_STOP=1 -q -v conninfo="$conninfo" -v publication="$MIGRATION_PUBLICATION" -v db="$db" <<'SQL'
SELECT :'publication' || '_' || left(:'db', 20) || '_' || left(md5(:'db'), 8) AS name \gset
CREATE SUBSCRIPTION :"name" CONNECTION :'conninfo' PUBLICATION :"publication" WITH (copy_data = true);
SQL
    else
        pg_dump "$source dbname=$db" --no-owner --no-acl -Fc | pg_restore -U postgres -d "$db" --no-owner --no-acl --exit-on-error
        own_restored "$db"
    fi
    echo "$db done"
done
COPY
databasify ctl run --quiet --collect --unit=databasify-postgres-migration --uid=postgres \
    --setenv=PGHOST={{ $socketDirectory }} --setenv=PGPORT={{ $clientPort }} \
    --setenv=SOURCE_HOST="$SOURCE_HOST" --setenv=SOURCE_PORT="$SOURCE_PORT" --setenv=SOURCE_USER="$SOURCE_USER" --setenv=PGPASSWORD="$PGPASSWORD" --setenv=SOURCE_SSLMODE="$SOURCE_SSLMODE" \
    --setenv=MIGRATION_DATABASES='{{ $databases }}' --setenv=SCHEMA_NAME='{!! $schemaName !!}' --setenv=MIGRATION_PUBLICATION='{{ $publication }}' --setenv=MIGRATION_OWNER='{{ $owner }}' --setenv=MIGRATION_CONTINUOUS='{{ $continuous ? 'true' : 'false' }}' \
    sh -c "sh $state/copy.sh > $state/copy.log 2>&1; echo \$? > $state/copy.exit"
echo "copying from {{ $address }} in the background"
