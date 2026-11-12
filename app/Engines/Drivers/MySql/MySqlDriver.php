<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MySql;

use App\Contracts\Engines\EngineDriver;
use App\Contracts\Engines\EngineReply;
use App\Contracts\Engines\EngineRequest;
use App\Engines\Drivers\MySqlFamily\Accounts;
use App\Engines\Drivers\MySqlFamily\Archive;
use App\Engines\Drivers\MySqlFamily\Migration;
use App\Engines\Drivers\MySqlFamily\Cluster;
use App\Engines\Drivers\TaskOperations;
use App\Engines\Drivers\MySqlFamily\ConfigCommands;
use App\Engines\Drivers\MySqlFamily\Configuration;
use App\Engines\Drivers\MySqlFamily\Lifecycle;
use App\Engines\Drivers\MySqlFamily\Readings;
use App\Engines\Requests\AccountList;
use App\Engines\Requests\ArchiveStatus;
use App\Engines\Requests\RestoreStart;
use App\Engines\Requests\MigrationCheck;
use App\Engines\Requests\MigrationStart;
use App\Engines\Requests\MigrationProgress;
use App\Engines\Requests\MigrationStop;
use App\Engines\Requests\ClusterDisable;
use App\Engines\Requests\ClusterEnable;
use App\Engines\Requests\ClusterFailover;
use App\Engines\Requests\ClusterForceQuorum;
use App\Engines\Requests\ClusterMembership;
use App\Engines\Requests\ClusterPosition;
use App\Engines\Requests\ClusterQuorum;
use App\Engines\Requests\ClusterRejoin;
use App\Engines\Requests\MemberForget;
use App\Engines\Requests\ConfigApply;
use App\Engines\Requests\ConfigFields;
use App\Engines\Requests\ConfigShow;
use App\Engines\Requests\ConfigSync;
use App\Engines\Requests\ConfigTune;
use App\Engines\Requests\DatabaseCreate;
use App\Engines\Requests\DatabaseDrop;
use App\Engines\Requests\NodeBootstrap;
use App\Engines\Requests\NodeConfigure;
use App\Engines\Requests\NodeMetrics;
use App\Engines\Requests\NodeReady;
use App\Engines\Requests\NodeReset;
use App\Engines\Requests\NodeRestart;
use App\Engines\Requests\NodeStart;
use App\Engines\Requests\NodeStatus;
use App\Engines\Requests\NodeWake;
use App\Engines\Requests\OperationCancel;
use App\Engines\Requests\OperationStatus;
use App\Engines\Requests\TlsInstall;
use App\Engines\Requests\UserCreate;
use App\Engines\Requests\UserDrop;
use App\Engines\Requests\UserGrant;
use App\Engines\Requests\UserSetPassword;
use App\Exceptions\Engines\EngineUnsupported;
use App\Models\DatabaseNode;
use App\Models\ServerLog;
use LogicException;

final readonly class MySqlDriver implements EngineDriver
{
    public function __construct(
        private TaskOperations $operations,
        private Readings $readings,
        private Accounts $accounts,
        private Configuration $configuration,
        private ConfigCommands $config,
        private Lifecycle $lifecycle,
        private Cluster $cluster,
        private Archive $archive,
        private Migration $migration,
    ) {}

    public function answers(EngineRequest $request): bool
    {
        return $request instanceof OperationStatus
            || $request instanceof OperationCancel
            || $request instanceof AccountList
            || $request instanceof DatabaseCreate
            || $request instanceof DatabaseDrop
            || $request instanceof UserCreate
            || $request instanceof UserSetPassword
            || $request instanceof UserGrant
            || $request instanceof UserDrop
            || $request instanceof NodeStatus
            || $request instanceof NodeMetrics
            || $request instanceof NodeConfigure
            || $request instanceof ConfigApply
            || $request instanceof ConfigSync
            || $request instanceof ConfigShow
            || $request instanceof ConfigFields
            || $request instanceof ConfigTune
            || $request instanceof NodeStart
            || $request instanceof NodeWake
            || $request instanceof NodeRestart
            || $request instanceof NodeReset
            || $request instanceof NodeBootstrap
            || $request instanceof TlsInstall
            || $request instanceof NodeReady
            || $request instanceof ClusterEnable
            || $request instanceof ClusterDisable
            || $request instanceof ClusterFailover
            || $request instanceof ClusterForceQuorum
            || $request instanceof ClusterMembership
            || $request instanceof ClusterPosition
            || $request instanceof ClusterQuorum
            || $request instanceof ClusterRejoin
            || $request instanceof MemberForget
            || $request instanceof ArchiveStatus
            || $request instanceof RestoreStart
            || $request instanceof MigrationCheck
            || $request instanceof MigrationStart
            || $request instanceof MigrationProgress
            || $request instanceof MigrationStop;
    }

    public function answer(DatabaseNode $node, EngineRequest $request, ?ServerLog $log): EngineReply
    {
        return match (true) {
            $request instanceof OperationStatus => $this->operations->status($node, $request->key),
            $request instanceof OperationCancel => $this->operations->cancel($node, $request->key),
            $request instanceof AccountList => $this->accounts->list($node),
            $request instanceof DatabaseCreate => $this->accounts->createDatabase($node, $request),
            $request instanceof DatabaseDrop => $this->accounts->dropDatabase($node, $request),
            $request instanceof UserCreate => $this->accounts->createUser($node, $request),
            $request instanceof UserSetPassword => $this->accounts->setPassword($node, $request),
            $request instanceof UserGrant => $this->accounts->grant($node, $request),
            $request instanceof UserDrop => $this->accounts->dropUser($node, $request),
            $request instanceof NodeStatus => $this->readings->status($node),
            $request instanceof NodeMetrics => $this->readings->metrics($node),
            $request instanceof NodeConfigure => $this->configuration->configure($node, $request),
            $request instanceof ConfigApply => $this->config->apply($node),
            $request instanceof ConfigSync => $this->config->sync(),
            $request instanceof ConfigShow => $this->config->show($node),
            $request instanceof ConfigFields => $this->config->fields($node),
            $request instanceof ConfigTune => $this->config->tune($node),
            $request instanceof NodeStart => $this->lifecycle->start($node),
            $request instanceof NodeWake => $this->lifecycle->wake($node),
            $request instanceof NodeRestart => $this->lifecycle->restart($node),
            $request instanceof NodeReset => throw new EngineUnsupported('unsupported', 'this engine does not implement "node reset"'),
            $request instanceof NodeBootstrap => $this->lifecycle->bootstrap($node, $request),
            $request instanceof TlsInstall => $this->lifecycle->installTls($node, $request),
            $request instanceof NodeReady => $this->lifecycle->ready($node, $request),
            $request instanceof ClusterEnable => $this->cluster->enable($node, $request),
            $request instanceof ClusterDisable => $this->cluster->disable($node),
            $request instanceof ClusterFailover => $this->cluster->failover($node, $request),
            $request instanceof ClusterForceQuorum => $this->cluster->forceQuorum($node, $request),
            $request instanceof ClusterMembership => $this->cluster->membership($node, $request),
            $request instanceof ClusterPosition => $this->cluster->position($node),
            $request instanceof ClusterQuorum => $this->cluster->quorum($node),
            $request instanceof ClusterRejoin => $this->cluster->rejoin($node, $request),
            $request instanceof MemberForget => $this->cluster->forget($node, $request),
            $request instanceof ArchiveStatus => $this->archive->status($node, $request),
            $request instanceof RestoreStart => $this->archive->restore($node, $request),
            $request instanceof MigrationCheck => $this->migration->check($node, $request),
            $request instanceof MigrationStart => $this->migration->start($node, $request),
            $request instanceof MigrationProgress => $this->migration->progress($node),
            $request instanceof MigrationStop => $this->migration->stop($node),
            default => throw new LogicException("The MySQL driver does not answer {$request->command()}."),
        };
    }
}
