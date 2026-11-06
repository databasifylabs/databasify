<?php

declare(strict_types=1);

namespace App\Providers;

use App\DatabaseProviders\Drivers\AbstractSentinelDriver;
use App\DatabaseProviders\Drivers\Mariadb\Mariadb;
use App\DatabaseProviders\Drivers\Mongodb\Mongodb;
use App\DatabaseProviders\Drivers\Mysql\Mysql;
use App\DatabaseProviders\Drivers\Postgres\Postgres;
use App\DatabaseProviders\Drivers\Redis\Redis;
use App\DatabaseProviders\Drivers\Valkey\Valkey;
use App\Plugins\RegisterDatabaseProvider;
use App\Support\Databases\ClientPorts;
use Illuminate\Support\ServiceProvider;

class DatabaseProviderServiceProvider extends ServiceProvider
{
    private const string CACHING = 'Caching';

    private const string APPLICATION = 'Application';

    public function boot(): void
    {
        $this->valkey();
        $this->redis();
        $this->mysql();
        $this->postgres();
        $this->mariadb();
        $this->mongodb();
    }

    private function valkey(): void
    {
        $this->sentinel(Valkey::id(), 'Valkey', Valkey::class)
            ->versions('9', [['value' => '8', 'label' => 'Valkey 8'], ['value' => '9', 'label' => 'Valkey 9']])
            ->register();
    }

    private function redis(): void
    {
        $this->sentinel(Redis::id(), 'Redis', Redis::class)
            ->versions('8', [['value' => '8', 'label' => 'Redis 8']])
            ->register();
    }

    private function mysql(): void
    {
        $this->engine(Mysql::id(), 'MySQL', Mysql::class, self::APPLICATION, 900)
            ->scripts('ssh.engines.mysql')
            ->peerPorts([Mysql::GROUP_PORT], lines: ['9.7' => []])
            ->rsaServerKey()
            ->failoverHint('MySQL needs three members to fail over on its own after a crash. Add one more standby, or use Recover from the Actions menu if the primary goes down.')
            ->versions('8.4', [['value' => '8.4', 'label' => 'MySQL 8.4 LTS'], ['value' => '9.7', 'label' => 'MySQL 9.7 LTS']])
            ->register();
    }

    private function postgres(): void
    {
        $this->engine(Postgres::id(), 'PostgreSQL', Postgres::class, self::APPLICATION, 120)
            ->scripts('ssh.engines.postgres')
            ->poolPort(ClientPorts::POOL)
            ->peerPorts([Postgres::PATRONI_PORT, Postgres::ETCD_CLIENT_PORT, Postgres::ETCD_PEER_PORT])
            ->failoverHint('Patroni needs three members for etcd to keep a majority and fail over on its own. Add one more standby.')
            ->versions('18', [['value' => '17', 'label' => 'PostgreSQL 17'], ['value' => '18', 'label' => 'PostgreSQL 18']])
            ->register();
    }

    private function mariadb(): void
    {
        $this->engine(Mariadb::id(), 'MariaDB', Mariadb::class, self::APPLICATION, 900)
            ->scripts('ssh.engines.mariadb')
            ->systemUser('mysql')
            ->peerPorts([Mariadb::SST_PORT, Mariadb::GALERA_PORT, Mariadb::IST_PORT])
            ->failoverHint('Galera needs three members to keep a majority when one fails. Add one more standby.')
            ->versions('11.8', [['value' => '11.4', 'label' => 'MariaDB 11.4 LTS'], ['value' => '11.8', 'label' => 'MariaDB 11.8 LTS']])
            ->register();
    }

    private function mongodb(): void
    {
        $this->engine(Mongodb::id(), 'MongoDB', Mongodb::class, self::APPLICATION, 300)
            ->scripts('ssh.engines.mongodb')
            ->nodeCertificates(['ca.crt', 'server.pem'])
            ->serviceRecord('_mongodb._tcp', 'replicaSet='.Mongodb::REPLICA_SET.'&authSource=admin')
            ->failoverHint('A MongoDB replica set needs three members to keep a majority when one fails. Add one more standby.')
            ->versions('8.0', [['value' => '7.0', 'label' => 'MongoDB 7.0'], ['value' => '8.0', 'label' => 'MongoDB 8.0'], ['value' => '9.0', 'label' => 'MongoDB 9.0', 'recovery' => false]])
            ->register();
    }

    /**
     * @param  class-string  $handler
     */
    private function engine(string $id, string $label, string $handler, string $category, int $stopSeconds): RegisterDatabaseProvider
    {
        return RegisterDatabaseProvider::make($id)->label($label)->handler($handler)->category($category)->stopSeconds($stopSeconds);
    }

    /**
     * @param  class-string  $handler
     */
    private function sentinel(string $id, string $label, string $handler): RegisterDatabaseProvider
    {
        return $this->engine($id, $label, $handler, self::CACHING, 120)
            ->scripts("ssh.engines.{$id}")
            ->port(ClientPorts::KEY_VALUE)
            ->peerPorts([AbstractSentinelDriver::DATA_PORT, AbstractSentinelDriver::SENTINEL_PORT])
            ->nodeCertificates(['ca.crt', 'server.pem'])
            ->persistenceChoice()
            ->publicCertificate()
            ->failoverHint('Sentinel needs three voting nodes to fail over on its own. Add a standby or a read-only node.');
    }
}
