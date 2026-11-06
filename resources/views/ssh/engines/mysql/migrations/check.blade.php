@include('ssh.engines.mysql.migrations.shell')
report=$(source_shell <<'PY'
@include('ssh.engines.mysql.migrations.config')
report = {'reachable': False}
system = ('mysql', 'sys', 'performance_schema', 'information_schema', 'mysql_innodb_cluster_metadata')
try:
    source = mysql.get_classic_session({'host': cfg['host'], 'port': cfg['port'], 'user': cfg['user'], 'password': os.environ['SOURCE_PASSWORD'], 'ssl-mode': 'REQUIRED' if cfg['tls'] else 'PREFERRED', 'connect-timeout': 10000})
    server = dict((str(row[0]), str(row[1])) for row in source.run_sql("SHOW GLOBAL VARIABLES WHERE Variable_name IN ('version', 'log_bin', 'binlog_format', 'gtid_mode', 'binlog_expire_logs_seconds', 'activate_all_roles_on_login')").fetch_all())
    size_mb = source.run_sql("SELECT COALESCE(ROUND(SUM(data_length + index_length) / 1048576, 1), 0) FROM information_schema.tables WHERE table_schema NOT IN ('mysql', 'sys', 'performance_schema', 'information_schema', 'mysql_innodb_cluster_metadata')").fetch_one()[0]
    grants = ' '.join(str(row[0]) for row in source.run_sql('SHOW GRANTS').fetch_all())
    visible = [str(row[0]) for row in source.run_sql('SELECT schema_name FROM information_schema.schemata').fetch_all()]
    schemas = cfg['databases'] or [name for name in visible if name not in system]
    report = {'reachable': True, 'version': server.get('version', ''), 'log_bin': '1' if server.get('log_bin') == 'ON' else '0', 'binlog_format': server.get('binlog_format', ''), 'gtid_mode': server.get('gtid_mode', ''), 'binlog_expire_logs_seconds': server.get('binlog_expire_logs_seconds', ''), 'activate_all_roles_on_login': server.get('activate_all_roles_on_login', ''), 'size_mb': str(size_mb), 'grants': grants, 'schemas': schemas, 'missing': [name for name in cfg['databases'] if name not in visible]}
    source.close()
except Exception as error:
    report['error'] = str(error)
print('REPORT ' + json.dumps(report))
print('SCHEMAS ' + ','.join("'" + name.replace('\\', '\\\\').replace("'", "''") + "'" for name in report.get('schemas', [])))
PY
) || true
printf '%s\n' "$report"
schemas=$(sed -n 's/^SCHEMAS //p' <<< "$report")
if [ -n "$schemas" ]; then
    target_sql() { timeout 60 mysql --defaults-extra-file={{ $directory }}/conf/root.cnf -N -B 2> /dev/null; }
    occupied=$(printf '%s' "SELECT CONCAT('SELECT ', QUOTE(table_schema), ' FROM \`', REPLACE(table_schema, '\`', '\`\`'), '\`.\`', REPLACE(table_name, '\`', '\`\`'), '\` LIMIT 1;') FROM information_schema.tables WHERE table_type = 'BASE TABLE' AND table_schema IN ($schemas)" \
        | target_sql | target_sql | sort -u | paste -sd, - || true)
    printf 'OCCUPIED %s\n' "$occupied"
fi
