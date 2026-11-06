GRANT REPLICATION SLAVE, REPLICATION CLIENT, BACKUP_ADMIN ON *.* TO '{!! $name !!}'@'%';
GRANT SELECT ON mysql.default_roles TO '{!! $name !!}'@'%';
GRANT SELECT ON mysql.role_edges TO '{!! $name !!}'@'%';
