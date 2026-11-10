<?php

declare(strict_types=1);

namespace App\Providers;

use App\Plugins\RegisterEngine;
use Illuminate\Support\ServiceProvider;

class EngineServiceProvider extends ServiceProvider
{
    private const string CACHING = 'Caching';

    private const string APPLICATION = 'Application';

    public function boot(): void
    {
        $this->sentinel('valkey', 'Valkey')
            ->versions('9', [['value' => '8', 'label' => 'Valkey 8'], ['value' => '9', 'label' => 'Valkey 9']])
            ->register();

        $this->sentinel('redis', 'Redis')
            ->versions('8', [['value' => '8', 'label' => 'Redis 8']])
            ->register();

        $this->engine('mysql', 'MySQL', self::APPLICATION)
            ->failoverHint('MySQL needs three members to fail over on its own after a crash. Add one more standby, or use Recover from the Actions menu if the primary goes down.')
            ->versions('8.4', [['value' => '8.4', 'label' => 'MySQL 8.4 LTS'], ['value' => '9.7', 'label' => 'MySQL 9.7 LTS']])
            ->register();

        $this->engine('postgres', 'PostgreSQL', self::APPLICATION)
            ->failoverHint('Patroni needs three members for etcd to keep a majority and fail over on its own. Add one more standby.')
            ->versions('18', [['value' => '17', 'label' => 'PostgreSQL 17'], ['value' => '18', 'label' => 'PostgreSQL 18']])
            ->register();

        $this->engine('mariadb', 'MariaDB', self::APPLICATION)
            ->failoverHint('Galera needs three members to keep a majority when one fails. Add one more standby.')
            ->versions('11.8', [['value' => '11.4', 'label' => 'MariaDB 11.4 LTS'], ['value' => '11.8', 'label' => 'MariaDB 11.8 LTS']])
            ->register();

        $this->engine('mongodb', 'MongoDB', self::APPLICATION)
            ->serviceRecord('_mongodb._tcp', 'replicaSet=databasify&authSource=admin')
            ->failoverHint('A MongoDB replica set needs three members to keep a majority when one fails. Add one more standby.')
            ->versions('8.0', [['value' => '7.0', 'label' => 'MongoDB 7.0'], ['value' => '8.0', 'label' => 'MongoDB 8.0'], ['value' => '9.0', 'label' => 'MongoDB 9.0', 'recovery' => false]])
            ->register();
    }

    private function engine(string $id, string $label, string $category): RegisterEngine
    {
        return RegisterEngine::make($id)->label($label)->category($category)->icon("assets/icons/database-providers/{$id}.svg");
    }

    private function sentinel(string $id, string $label): RegisterEngine
    {
        return $this->engine($id, $label, self::CACHING)
            ->persistenceChoice()
            ->publicCertificate()
            ->failoverHint('Sentinel needs three voting nodes to fail over on its own. Add a standby or a read-only node.');
    }
}
