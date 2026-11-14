<?php

declare(strict_types=1);

namespace App\Jobs\Databases;

use App\Actions\Databases\Domains\ClearNodeDns;
use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Actions\Databases\Network\ApplyNodeFirewall;
use App\Actions\Databases\Nodes\BuildNodeConfig;
use App\Actions\Databases\Nodes\DeployNode;
use App\Engines\Data\Operation;
use App\Engines\Runtime\Runtime;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Queues\QueueGroup;
use App\Jobs\Concerns\FinishesOperation;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Services\Databases\EngineTasks;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

#[Queue(QueueGroup::Databases)]
#[Tries(1)]
#[Timeout(7200)]
#[DeleteWhenMissingModels]
final class AddNodeJob implements ShouldQueue
{
    use FinishesOperation, Queueable;

    public function __construct(
        public Database $database,
        public DatabaseNode $node,
    ) {}

    public function handle(DeployNode $deploy, ApplyNodeFirewall $firewall, BuildNodeConfig $nodeConfig, EngineTasks $tasks, RefreshDatabaseStatus $refresh): void
    {
        $database = $this->database;
        $joiner = $this->node->load('server');
        $existing = $database->nodesOtherThan($joiner)->load('server')->sortBy('position')->values();

        $deploy->handle($database, $joiner);
        $database->nodes()->with('server')->get()->each(fn (DatabaseNode $node) => $firewall->handle($database, $node, $database->trustedCidrs()));
        $joiner->driver()->nodes()->configure($nodeConfig->handle($database, $joiner));
        $joiner->driver()->nodes()->start();
        $tasks->await($joiner, 'waiting for the node', fn (string $key): Operation => $joiner->driver()->nodes()->ready($key, expectPrimary: false));

        $existing->each(function (DatabaseNode $node) use ($database, $nodeConfig): void {
            $node->driver()->nodes()->configure($nodeConfig->handle($database, $node));
            $node->driver()->cluster()->membership($nodeConfig->membersOf($database));
        });

        if ($existing->count() === 1) {
            $peer = $existing->sole();
            $tasks->await($peer, 'enabling the cluster', fn (string $key): Operation => $peer->driver()->cluster()->enable($key));
        }

        $tasks->await($joiner, 'copying the data', fn (string $key): Operation => $joiner->driver()->cluster()->enable($key));
        $database->matchModeToMembers();
        $refresh->handle($database);
    }

    protected function endFailed(Database $database): void
    {
        $joiner = $database->nodes()->with('server')->find($this->node->id);

        if ($joiner !== null) {
            $this->removeJoiner($database, $joiner);
        }

        rescue(fn () => resolve(RefreshDatabaseStatus::class)->handle($database));
        $database->markReady();
    }

    protected function abandon(Database $database): void
    {
        $this->endFailed($database);
    }

    protected function operatingStatus(): DatabaseStatus
    {
        return DatabaseStatus::Scaling;
    }

    protected function operationName(): string
    {
        return "Add {$this->node->kind->label()} node {$this->node->displayName()}";
    }

    private function removeJoiner(Database $database, DatabaseNode $joiner): void
    {
        $nodeConfig = resolve(BuildNodeConfig::class);
        rescue(fn () => resolve(Runtime::class)->remove($joiner, erase: true));
        rescue(fn () => resolve(ClearNodeDns::class)->handle($joiner));
        $joiner->delete();
        $database->forgetNodeCertificates($joiner);

        $database->nodes()->with('server')->get()->each(function (DatabaseNode $node) use ($database, $nodeConfig): void {
            rescue(fn () => $node->driver()->nodes()->configure($nodeConfig->handle($database, $node)));
            rescue(fn () => $node->driver()->cluster()->membership($nodeConfig->membersOf($database)));
        });
    }
}
