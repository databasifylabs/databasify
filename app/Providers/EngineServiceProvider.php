<?php

declare(strict_types=1);

namespace App\Providers;

use App\DTOs\Databases\ConnectionProfile;
use App\DTOs\Databases\ServiceConnection;
use App\Plugins\RegisterEngine;
use Illuminate\Support\ServiceProvider;

class EngineServiceProvider extends ServiceProvider
{
    private const string CACHING = 'Caching';

    private const string APPLICATION = 'Application';

    private const array SQL_ENV_KEYS = ['host' => 'DB_HOST', 'port' => 'DB_PORT', 'username' => 'DB_USERNAME', 'password' => 'DB_PASSWORD', 'database' => 'DB_DATABASE'];

    private const string MYSQL_ENV_NOTE = "Laravel connects without TLS unless MYSQL_ATTR_SSL_CA is set. To encrypt without the CA, set 'options' => [Mysql::ATTR_SSL_CIPHER => 'DEFAULT', Mysql::ATTR_SSL_VERIFY_SERVER_CERT => false] on the connection in config/database.php. To verify the server, download the CA certificate and add MYSQL_ATTR_SSL_CA=/path/to/{ca}.";

    private const string MONGODB_VERIFY_NOTE = 'To verify the server, download the CA certificate and replace tlsAllowInvalidCertificates=true with tlsCAFile=/path/to/{ca}.';

    public function boot(): void
    {
        $this->sentinel('valkey', 'Valkey', 'valkey-cli')
            ->versions('9', [['value' => '7', 'label' => 'Valkey 7'], ['value' => '8', 'label' => 'Valkey 8'], ['value' => '9', 'label' => 'Valkey 9']])
            ->register();

        $this->sentinel('redis', 'Redis', 'redis-cli')
            ->versions('8', [['value' => '7.2', 'label' => 'Redis 7.2'], ['value' => '8', 'label' => 'Redis 8']])
            ->register();

        $this->engine('mysql', 'MySQL', self::APPLICATION)
            ->migrationSource(3306)
            ->connection(new ConnectionProfile('mysql', '?ssl-mode=REQUIRED', 'sslmode', 'REQUIRED', 'mysql -u {username} -p{password} -h {host} -P {port} -D {database} --ssl-mode=REQUIRED', null, true, self::SQL_ENV_KEYS, self::MYSQL_ENV_NOTE, 'With the MariaDB client, use --ssl --ssl-verify-server-cert=0 instead of --ssl-mode=REQUIRED. To verify the server, download the CA certificate and use --ssl-mode=VERIFY_IDENTITY --ssl-ca={ca}.'))
            ->environment('mysql')
            ->failoverHint('MySQL needs three members to fail over on its own after a crash. Add one more standby, or use Recover from the Actions menu if the primary goes down.')
            ->versions('8.4', [['value' => '8.4', 'label' => 'MySQL 8.4 LTS'], ['value' => '9.7', 'label' => 'MySQL 9.7 LTS']])
            ->register();

        $this->engine('postgres', 'PostgreSQL', self::APPLICATION)
            ->migrationSource(5432)
            ->connection(new ConnectionProfile('postgresql', '?sslmode=require', 'sslmode', 'require', 'PGSSLMODE=require PGPASSWORD={password} psql -U {username} -h {host} -p {port} -d {database}', null, true, self::SQL_ENV_KEYS, 'To verify the server, download the CA certificate and use sslmode=verify-full with sslrootcert=/path/to/{ca}.', 'To verify the server, download the CA certificate and use PGSSLMODE=verify-full PGSSLROOTCERT={ca}.'))
            ->environment('pgsql', ['DB_SSLMODE' => 'require'])
            ->failoverHint('Patroni needs three members for etcd to keep a majority and fail over on its own. Add one more standby.')
            ->versions('18', [['value' => '15', 'label' => 'PostgreSQL 15'], ['value' => '16', 'label' => 'PostgreSQL 16'], ['value' => '17', 'label' => 'PostgreSQL 17'], ['value' => '18', 'label' => 'PostgreSQL 18']])
            ->register();

        $this->engine('mariadb', 'MariaDB', self::APPLICATION)
            ->migrationSource(3306)
            ->connection(new ConnectionProfile('mysql', '?ssl-mode=REQUIRED', 'sslmode', 'REQUIRED', 'mariadb -u {username} -p{password} -h {host} -P {port} -D {database} --ssl', null, true, self::SQL_ENV_KEYS, self::MYSQL_ENV_NOTE, 'If the client cannot verify the server through your password, add --ssl-verify-server-cert=0. To verify the server with the CA certificate, download it and add --ssl-ca={ca}.'))
            ->environment('mariadb')
            ->failoverHint('Galera needs three members to keep a majority when one fails. Add one more standby.')
            ->versions('11.8', [['value' => '10.11', 'label' => 'MariaDB 10.11 LTS'], ['value' => '11.4', 'label' => 'MariaDB 11.4 LTS'], ['value' => '11.8', 'label' => 'MariaDB 11.8 LTS'], ['value' => '12.3', 'label' => 'MariaDB 12.3 LTS']])
            ->register();

        $this->engine('mongodb', 'MongoDB', self::APPLICATION)
            ->migrationSource(27017)
            ->serviceRecord('_mongodb._tcp', 'replicaSet=databasify&authSource=admin')
            ->connection(
                new ConnectionProfile('mongodb', '?tls=true&tlsAllowInvalidCertificates=true&authSource=admin&directConnection=true', 'tls', 'true', 'mongosh "mongodb://{username}:{password}@{host}:{port}/{database}?tls=true&tlsAllowInvalidCertificates=true&authSource=admin&directConnection=true"', null, true, self::SQL_ENV_KEYS, 'Users authenticate against the admin database: set authSource=admin, tls=true and tlsAllowInvalidCertificates=true in your MongoDB connection options. '.self::MONGODB_VERIFY_NOTE, 'To verify the server, download the CA certificate and replace tlsAllowInvalidCertificates=true with tlsCAFile={ca}.'),
                service: new ServiceConnection('mongodb+srv', '?tls=true&tlsAllowInvalidCertificates=true', 'mongosh "mongodb+srv://{username}:{password}@{host}/{database}?tlsAllowInvalidCertificates=true"', 'Connect with the mongodb+srv:// scheme and no port: DNS lists every member, the replica set and the auth source, and the driver follows failovers itself. Set tlsAllowInvalidCertificates=true in your MongoDB connection options. '.self::MONGODB_VERIFY_NOTE, '?tls=true&tlsAllowInvalidCertificates=true&readPreference=secondaryPreferred', 'mongosh "mongodb+srv://{username}:{password}@{host}/{database}?tlsAllowInvalidCertificates=true&readPreference=secondaryPreferred"'),
            )
            ->environment('mongodb')
            ->failoverHint('A MongoDB replica set needs three members to keep a majority when one fails. Add one more standby.')
            ->versions('8.0', [['value' => '7.0', 'label' => 'MongoDB 7.0'], ['value' => '8.0', 'label' => 'MongoDB 8.0'], ['value' => '9.0', 'label' => 'MongoDB 9.0', 'recovery' => false]])
            ->register();
    }

    private function engine(string $id, string $label, string $category): RegisterEngine
    {
        return RegisterEngine::make($id)->label($label)->category($category)->icon("assets/icons/database-providers/{$id}.svg");
    }

    private function sentinel(string $id, string $label, string $cli): RegisterEngine
    {
        $keys = ['host' => 'REDIS_HOST', 'port' => 'REDIS_PORT', 'username' => null, 'password' => 'REDIS_PASSWORD', 'database' => null];

        return $this->engine($id, $label, self::CACHING)
            ->migrationSource(6379, credentialsRequired: false, selectsDatabases: false)
            ->connection(
                new ConnectionProfile('rediss', '', 'tls', 'on', "{$cli} --tls --insecure -u rediss://{username}:{password}@{host}:{port}", 'default', false, $keys, "phpredis verifies the server: to connect without the CA, add 'context' => ['stream' => ['verify_peer' => false, 'verify_peer_name' => false]] to the redis connections in config/database.php. To verify the server, download the CA certificate and use 'context' => ['stream' => ['cafile' => '/path/to/{ca}']] instead.", 'To verify the server, download the CA certificate and replace --insecure with --cacert {ca}.'),
                withPublicCertificate: new ConnectionProfile('rediss', '', 'tls', 'on', "{$cli} --tls -u rediss://{username}:{password}@{host}:{port}", 'default', false, $keys),
            )
            ->environment(null)
            ->readOnlyNodesVote()
            ->persistenceChoice()
            ->publicCertificate()
            ->failoverHint('Sentinel needs three voting nodes to fail over on its own. Add a standby or a read-only node.');
    }
}
