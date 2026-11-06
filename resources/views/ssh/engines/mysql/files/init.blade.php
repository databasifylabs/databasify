SET sql_log_bin = 0;
ALTER USER 'root'@'localhost' IDENTIFIED BY {!! $rootPassword !!};
CREATE USER IF NOT EXISTS 'clusteradmin'@'%' IDENTIFIED BY {!! $clusterAdminPassword !!};
GRANT ALL PRIVILEGES ON *.* TO 'clusteradmin'@'%' WITH GRANT OPTION;
SET sql_log_bin = 1;
