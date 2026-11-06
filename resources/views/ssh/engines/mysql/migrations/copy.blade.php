@include('ssh.engines.mysql.migrations.shell')
@include('ssh.engines.mysql.migrations.helpers')
stop_copy
rm -rf "$state"
install -d -m 700 "$state"
cat > "$state/migration.py" <<'PY'
@include('ssh.engines.mysql.migrations.config')
shell.connect({'host': cfg['host'], 'port': cfg['port'], 'user': cfg['user'], 'password': os.environ['SOURCE_PASSWORD'], 'ssl-mode': 'REQUIRED' if cfg['tls'] else 'PREFERRED'})
dump = {'threads': 4, 'consistent': cfg['consistent'], 'compatibility': ['force_innodb', 'strip_definers'], 'showProgress': False}
if cfg['databases']:
    util.dump_schemas(cfg['databases'], cfg['dumpDir'], dump)
else:
    dump['users'] = False
    dump['excludeSchemas'] = ['mysql_innodb_cluster_metadata']
    util.dump_instance(cfg['dumpDir'], dump)
shell.disconnect()
print('dump finished, loading into the database')
target = shell.connect({'user': 'root', 'socket': '{{ $socket }}', 'password': os.environ['MYSQL_ROOT_PASSWORD']})
target.run_sql('SET GLOBAL local_infile = ON')
try:
    util.load_dump(cfg['dumpDir'], {'threads': 4, 'createInvisiblePKs': True, 'loadUsers': False, 'ignoreVersion': True, 'ignoreExistingObjects': True, 'updateGtidSet': 'off', 'skipBinlog': False, 'showProgress': False})
finally:
    target.run_sql('SET GLOBAL local_infile = OFF')
with open(cfg['dumpDir'] + '/@.json') as metadata:
    meta = json.load(metadata)
print('POSITION ' + json.dumps({'file': str(meta.get('binlogFile') or ''), 'position': str(meta.get('binlogPosition') or '')}))
PY
databasify ctl run --quiet --collect --unit=databasify-mysql-migration \
    --setenv=SOURCE_PASSWORD="$SOURCE_PASSWORD" \
    --setenv=MYSQL_ROOT_PASSWORD="$(sed -n 's/^password = //p' {{ $directory }}/conf/root.cnf)" \
    sh -c "mysqlsh --no-defaults --no-wizard --py --quiet-start=2 --file=$state/migration.py > $state/copy.log 2>&1; echo \$? > $state/copy.exit"
echo "copying from {{ $address }} in the background"
