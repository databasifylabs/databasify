@include('ssh.engines.mariadb.helpers')
@include('ssh.engines.mariadb.cluster.sst-user')
mariadb -N -B <<SQL
CREATE USER IF NOT EXISTS 'replicator'@'%' IDENTIFIED BY '{{ $secrets['REPLICATION_PASSWORD'] ?? '' }}' REQUIRE SSL;
GRANT REPLICATION SLAVE ON *.* TO 'replicator'@'%';
CREATE DATABASE IF NOT EXISTS databasify;
CREATE TABLE IF NOT EXISTS databasify.writer (id TINYINT PRIMARY KEY, ip VARCHAR(45) NOT NULL);
INSERT IGNORE INTO databasify.writer VALUES (1, '{{ $primary }}');
SQL
echo '{{ base64_encode($sql) }}' | msql_from
echo "database {{ $schema }} and user {{ $username }} ready"
