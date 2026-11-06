@include('ssh.engines.mariadb.migrations.env')
source_query() { in_db sh -c 'mariadb -h "$SOURCE_HOST" -P "$SOURCE_PORT" -u "$SOURCE_USER" $SOURCE_SSL --connect-timeout=10 -N -B -e "$1" 2>&1' sh "$1"; }
if ! version=$(source_query "SELECT VERSION()"); then
    echo "reachable=no"
    printf 'error=%s\n' "$(printf '%s' "$version" | tr '\n' ' ')"
    exit 0
fi
echo "reachable=yes"
printf 'version=%s\n' "$version"
printf 'log_bin=%s\n' "$(source_query "SELECT @@@log_bin")"
printf 'binlog_format=%s\n' "$(source_query "SELECT @@@binlog_format")"
printf 'grants=%s\n' "$(source_query "SHOW GRANTS" | tr '\n' ' ')"
printf 'size_mb=%s\n' "$(source_query "SELECT COALESCE(ROUND(SUM(data_length + index_length) / 1048576), 0) FROM information_schema.tables WHERE table_schema NOT IN ('mysql', 'sys', 'performance_schema', 'information_schema')")"
printf 'databases=%s\n' "$(source_query "SELECT COALESCE(GROUP_CONCAT(schema_name ORDER BY schema_name SEPARATOR ' '), '') FROM information_schema.schemata WHERE schema_name NOT IN ('mysql', 'sys', 'performance_schema', 'information_schema')")"
