<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Sentinel;

use App\Contracts\Engines\EngineReply;
use App\Contracts\Engines\EngineRequest;
use App\Engines\Drivers\TaskOperations;
use App\Engines\Requests\AccountList;
use App\Engines\Requests\ArchiveStatus;
use App\Engines\Requests\ClusterDisable;
use App\Engines\Requests\ClusterEnable;
use App\Engines\Requests\ClusterFailover;
use App\Engines\Requests\ClusterForceQuorum;
use App\Engines\Requests\ClusterMembership;
use App\Engines\Requests\ClusterPosition;
use App\Engines\Requests\ClusterQuorum;
use App\Engines\Requests\ClusterRejoin;
use App\Engines\Requests\ConfigApply;
use App\Engines\Requests\ConfigFields;
use App\Engines\Requests\ConfigShow;
use App\Engines\Requests\ConfigSync;
use App\Engines\Requests\ConfigTune;
use App\Engines\Requests\DatabaseCreate;
use App\Engines\Requests\DatabaseDrop;
use App\Engines\Requests\MemberForget;
use App\Engines\Requests\MigrationCheck;
use App\Engines\Requests\MigrationProgress;
use App\Engines\Requests\MigrationStart;
use App\Engines\Requests\MigrationStop;
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
use App\Engines\Requests\RestoreStart;
use App\Engines\Requests\TlsInstall;
use App\Engines\Requests\UserCreate;
use App\Engines\Requests\UserDrop;
use App\Engines\Requests\UserGrant;
use App\Engines\Requests\UserSetPassword;
use App\Engines\Responses\Done;
use App\Engines\Responses\NodeMetrics as NodeMetricsReply;
use App\Engines\Responses\NodeStatus as NodeStatusReply;
use App\Enums\Databases\NodeRole;
use App\Exceptions\Engines\EngineUnsupported;
use App\Models\DatabaseNode;

final readonly class SentinelRequests
{
    private const array UNSUPPORTED = [AccountList::class, DatabaseCreate::class, DatabaseDrop::class, UserCreate::class, UserSetPassword::class, UserGrant::class, UserDrop::class, ArchiveStatus::class, RestoreStart::class, MemberForget::class];

    private const array ANSWERED = [
        OperationStatus::class, OperationCancel::class, NodeStatus::class, NodeMetrics::class, NodeConfigure::class, ConfigApply::class, ConfigSync::class, ConfigShow::class,
        ConfigFields::class, ConfigTune::class, NodeStart::class, NodeWake::class, NodeRestart::class, NodeReset::class, NodeBootstrap::class, TlsInstall::class, NodeReady::class,
        ClusterEnable::class, ClusterDisable::class, ClusterFailover::class, ClusterForceQuorum::class, ClusterMembership::class, ClusterPosition::class, ClusterQuorum::class,
        ClusterRejoin::class, MigrationCheck::class, MigrationStart::class, MigrationProgress::class, MigrationStop::class,
    ];

    public function __construct(
        private TaskOperations $operations,
        private SentinelScripts $scripts,
        private Configuration $configuration,
        private ConfigCommands $config,
        private Lifecycle $lifecycle,
        private Cluster $cluster,
        private Migration $migration,
    ) {}

    /**
     * @param  EngineRequest<EngineReply>  $request
     */
    public function answers(EngineRequest $request): bool
    {
        return in_array($request::class, [...self::ANSWERED, ...self::UNSUPPORTED], true);
    }

    /**
     * @param  EngineRequest<EngineReply>  $request
     */
    public function answer(DatabaseNode $node, EngineRequest $request): ?EngineReply
    {
        if (in_array($request::class, self::UNSUPPORTED, true)) {
            throw new EngineUnsupported('unsupported', "this engine does not implement \"{$request->command()}\"");
        }

        return match (true) {
            $request instanceof OperationStatus => $this->operations->status($node, $request->key),
            $request instanceof OperationCancel => $this->operations->cancel($node, $request->key),
            $request instanceof NodeStatus => $this->status($node),
            $request instanceof NodeMetrics => NodeMetricsReply::fromPayload($this->scripts->run($node, 'nodes/metrics')->json()),
            $request instanceof NodeConfigure => $this->configuration->configure($node, $request),
            $request instanceof ConfigApply => $this->config->apply($node),
            $request instanceof ConfigSync => $this->config->sync(),
            $request instanceof ConfigShow => $this->config->show($node),
            $request instanceof ConfigFields => $this->config->fields($node),
            $request instanceof ConfigTune => $this->config->tune($node),
            $request instanceof NodeStart => $this->lifecycle->start($node),
            $request instanceof NodeWake => $this->lifecycle->wake($node),
            $request instanceof NodeRestart => $this->lifecycle->restart($node),
            $request instanceof NodeReset => $this->lifecycle->reset($node, $request),
            $request instanceof NodeBootstrap => new Done,
            $request instanceof TlsInstall => $this->lifecycle->installTls($node, $request),
            $request instanceof NodeReady => $this->lifecycle->ready($node, $request),
            $request instanceof ClusterEnable => $this->cluster->enable($node, $request),
            $request instanceof ClusterDisable => $this->cluster->disable($node),
            $request instanceof ClusterFailover => $this->cluster->failover($node, $request),
            $request instanceof ClusterForceQuorum => $this->cluster->forceQuorum($node),
            $request instanceof ClusterMembership => $this->cluster->membership($node, $request),
            $request instanceof ClusterPosition => $this->cluster->position($node),
            $request instanceof ClusterQuorum => $this->cluster->quorum($node),
            $request instanceof ClusterRejoin => $this->cluster->rejoin($node, $request),
            $request instanceof MigrationCheck => $this->migration->check($node, $request),
            $request instanceof MigrationStart => $this->migration->start($node, $request),
            $request instanceof MigrationProgress => $this->migration->progress($node),
            $request instanceof MigrationStop => $this->migration->stop($node),
            default => null,
        };
    }

    private function status(DatabaseNode $node): NodeStatusReply
    {
        $reply = $this->scripts->run($node, 'nodes/status')->json();

        return new NodeStatusReply(NodeRole::from($reply->string('role')), $reply->nullableString('version'));
    }
}
