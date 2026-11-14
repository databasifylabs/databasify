<?php

declare(strict_types=1);

namespace App\Jobs\Databases;

use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Actions\Databases\Nodes\BuildNodeConfig;
use App\Actions\Databases\Recovery\RefreshRecoveryStatus;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Queues\QueueGroup;
use App\Jobs\Concerns\FinishesOperation;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Services\Databases\RollingNodes;
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
final class ApplySettingsJob implements ShouldQueue
{
    use FinishesOperation, Queueable;

    public function __construct(
        public Database $database,
        public bool $archiveChanged = false,
    ) {}

    public function handle(RollingNodes $rolling, BuildNodeConfig $nodeConfig, RefreshDatabaseStatus $refresh, RefreshRecoveryStatus $recovery): void
    {
        $database = $this->database;
        $primary = $database->primaryOrSeed();

        $rolling->each($database, fn (DatabaseNode $node) => $node->driver()->nodes()->configure($nodeConfig->handle($database, $node)), failover: false);
        $primary->driver()->config()->sync();
        $rolling->each($database, fn (DatabaseNode $node) => $node->driver()->config()->apply(), failover: false);

        if ($primary->driver()->config()->pendingRestart() !== []) {
            $rolling->restart($database);
        }

        $refresh->handle($database);
        $archive = $database->refresh()->pointInTimeRecovery;

        if ($this->archiveChanged && $archive !== null) {
            $recovery->handle($archive, backUpNow: true);
        }
    }

    protected function operatingStatus(): DatabaseStatus
    {
        return DatabaseStatus::Updating;
    }

    protected function operationName(): string
    {
        if (! $this->archiveChanged) {
            return 'Apply settings';
        }

        return $this->database->pointInTimeRecovery === null ? 'Turn off point-in-time recovery' : 'Update point-in-time recovery';
    }
}
