set -euo pipefail
@include('engines.mysql-family._mysql')
@include('engines.mysql-family._files')
[[ $MIGRATION_PORT =~ ^[0-9]+$ ]] || fail_task 'the source port is not a number'
ssl=0
if [ "$MIGRATION_TLS" = true ]; then
    ssl=1
fi
read -ra databases <<< "$MIGRATION_DATABASES"
@if ($engine->id === 'mysql')
admin_password=$(sed -n 's/^[[:space:]]*password=//p' "$ROOT/conf/admin.cnf" | head -n 1)
my '' <<< 'SET GLOBAL local_infile = ON' > /dev/null
trap 'my "" <<< "SET GLOBAL local_infile = OFF" > /dev/null 2>&1 || true' EXIT
ssl_mode=PREFERRED
if [ "$ssl" = 1 ]; then
    ssl_mode=REQUIRED
fi
args=(--passwords-from-stdin --uri="$MYSQLSH_URI" --ssl-mode="$ssl_mode" -- util copy-schemas "${databases[@]}" --host=127.0.0.1 --port=25060 --user=root --threads=4)
if [ "$MIGRATION_CONTINUOUS" = true ]; then
    args+=(--updateGtidSet=append)
fi
printf '%s\n%s\n' "$MIGRATION_PASSWORD" "$admin_password" | LC_ALL=C.UTF-8 mysqlsh "${args[@]}" \
    || fail_task 'MySQL Shell could not copy the schemas'
[ "$MIGRATION_CONTINUOUS" = true ] || exit 0
wild=''
for database in "${databases[@]}"; do
    wild+="${wild:+, }$(my_string "$database.%")"
done
my '' > /dev/null <<SQL
CHANGE REPLICATION SOURCE TO SOURCE_HOST = $(my_string "$MIGRATION_HOST"), SOURCE_PORT = $MIGRATION_PORT, SOURCE_USER = $(my_string "$MIGRATION_USER"), SOURCE_PASSWORD = $(my_string "$MIGRATION_PASSWORD"), SOURCE_AUTO_POSITION = 1, SOURCE_SSL = $ssl FOR CHANNEL 'migration';
CHANGE REPLICATION FILTER REPLICATE_WILD_DO_TABLE = ($wild) FOR CHANNEL 'migration';
START REPLICA FOR CHANNEL 'migration';
SQL
@else
@include('engines._write')
@include('engines.mariadb._remote')
@include('engines.mariadb._dump')
source_cnf=$(mktemp)
trap 'rm -f "$source_cnf"' EXIT
source_cnf_of "$MIGRATION_HOST" "$MIGRATION_PORT" "$MIGRATION_USER" "$MIGRATION_PASSWORD" "$MIGRATION_TLS" > "$source_cnf"
@verbatim
version_query='SELECT @@version'
@endverbatim
version=$("$MY_CLIENT" --defaults-extra-file="$source_cnf" -N -B -e "$version_query" 2>&1) || fail_task "could not read the source version: $version"
from_mariadb=false
if [[ $version == *MariaDB* ]]; then
    from_mariadb=true
fi
copy_from "$MIGRATION_HOST" "$MIGRATION_PORT" "$MIGRATION_USER" "$MIGRATION_PASSWORD" "$MIGRATION_TLS" "$from_mariadb" "${databases[@]}" \
    || fail_task "$ATTEMPT_ERROR"
[ "$MIGRATION_CONTINUOUS" = true ] || exit 0
if [ "$from_mariadb" != true ] || [ -z "$COPIED_GTID" ]; then
    fail_task 'the source gave no MariaDB GTID position, so it cannot be followed'
fi
my_try '' <<< "STOP SLAVE 'migration'"
if [ -n "$MY_ERROR" ] && [[ $MY_ERROR_TEXT != *1617* ]]; then
    fail_task "$MY_ERROR_TEXT"
fi
my '' > /dev/null <<SQL
SET GLOBAL gtid_slave_pos = $(my_string "$COPIED_GTID");
CHANGE MASTER 'migration' TO MASTER_HOST = $(my_string "$MIGRATION_HOST"), MASTER_PORT = $MIGRATION_PORT, MASTER_USER = $(my_string "$MIGRATION_USER"), MASTER_PASSWORD = $(my_string "$MIGRATION_PASSWORD"), MASTER_SSL = $ssl, MASTER_SSL_VERIFY_SERVER_CERT = 0, MASTER_USE_GTID = slave_pos;
SET GLOBAL migration.replicate_do_db = $(my_string "$(IFS=,; printf '%s' "${databases[*]}")");
START SLAVE 'migration';
SQL
@endif
