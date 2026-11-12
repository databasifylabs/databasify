@include('engines.mysql-family._mysql')
@include('engines.mysql-family._files')
if [ -e "$ROOT/state/replica" ]; then
    my '' > /dev/null <<'SQL'
STOP REPLICA FOR CHANNEL 'group';
RESET REPLICA ALL FOR CHANNEL 'group'
SQL
    rm -f "$ROOT/state/replica"
    my '' > /dev/null <<< 'SET GLOBAL super_read_only = OFF'
    result '{}'
    exit 0
fi
my '' > /dev/null <<'SQL'
STOP GROUP_REPLICATION;
SET GLOBAL super_read_only = OFF
SQL
rm -f "$ROOT/conf/conf.d/grouped.cnf" "$ROOT/state/grouped"
result '{}'
