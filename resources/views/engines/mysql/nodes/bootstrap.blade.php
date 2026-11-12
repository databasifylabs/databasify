@include('engines.mysql-family._mysql')
@include('engines.mysql-family._files')
@include('engines.mysql-family._saved')
@include('engines.mysql-family._seed')
my '' > /dev/null <<SQL
CREATE USER IF NOT EXISTS 'databasify_repl'@'%' IDENTIFIED WITH caching_sha2_password BY $replication REQUIRE SSL;
ALTER USER 'databasify_repl'@'%' IDENTIFIED BY $replication;
GRANT REPLICATION SLAVE, BACKUP_ADMIN, CLONE_ADMIN, GROUP_REPLICATION_STREAM, CONNECTION_ADMIN ON *.* TO 'databasify_repl'@'%';
GRANT SELECT ON performance_schema.replication_group_members TO 'databasify_repl'@'%';
CREATE USER IF NOT EXISTS 'databasify_agent'@'localhost' IDENTIFIED WITH caching_sha2_password BY $agent;
ALTER USER 'databasify_agent'@'localhost' IDENTIFIED BY $agent;
GRANT BACKUP_ADMIN, PROCESS, RELOAD, LOCK TABLES, REPLICATION CLIENT, REPLICATION SLAVE ON *.* TO 'databasify_agent'@'localhost';
GRANT SELECT ON performance_schema.log_status TO 'databasify_agent'@'localhost';
GRANT SELECT ON performance_schema.keyring_component_status TO 'databasify_agent'@'localhost';
GRANT SELECT ON performance_schema.replication_group_members TO 'databasify_agent'@'localhost'
SQL
result '{"seed":true}'
