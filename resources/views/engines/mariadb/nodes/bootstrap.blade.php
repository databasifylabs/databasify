@include('engines.mysql-family._mysql')
@include('engines.mysql-family._files')
@include('engines.mysql-family._saved')
@include('engines.mariadb._state')
@include('engines.mariadb._galera')
@include('engines.mysql-family._seed')
my '' > /dev/null <<SQL
CREATE USER IF NOT EXISTS 'databasify_sst'@'localhost' IDENTIFIED BY $replication;
ALTER USER 'databasify_sst'@'localhost' IDENTIFIED BY $replication;
GRANT RELOAD, PROCESS, LOCK TABLES, BINLOG MONITOR ON *.* TO 'databasify_sst'@'localhost';
CREATE USER IF NOT EXISTS 'databasify_repl'@'%' IDENTIFIED BY $replication REQUIRE SSL;
ALTER USER 'databasify_repl'@'%' IDENTIFIED BY $replication;
GRANT SELECT, SHOW VIEW, TRIGGER, EVENT, RELOAD, LOCK TABLES, REPLICATION SLAVE, BINLOG MONITOR, READ_ONLY ADMIN ON *.* TO 'databasify_repl'@'%';
GRANT ALL PRIVILEGES ON \`databasify\`.* TO 'databasify_repl'@'%';
CREATE USER IF NOT EXISTS 'databasify_agent'@'localhost' IDENTIFIED BY $agent;
ALTER USER 'databasify_agent'@'localhost' IDENTIFIED BY $agent;
GRANT RELOAD, PROCESS, LOCK TABLES, BINLOG MONITOR, REPLICATION SLAVE, SLAVE MONITOR ON *.* TO 'databasify_agent'@'localhost'
SQL
if saved_is '.clustered and (.readOnly | not)'; then
    become_writer_if_recorded "$(saved .ip)"
fi
result '{"seed":true}'
