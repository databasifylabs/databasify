@include('ssh.engines.mariadb.helpers')
msql "SELECT DISTINCT CONCAT('user:', user) FROM mysql.user UNION ALL SELECT CONCAT('database:', schema_name) FROM information_schema.schemata"
