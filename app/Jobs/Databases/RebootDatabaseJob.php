<?php

declare(strict_types=1);

namespace App\Jobs\Databases;

use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
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
use Illuminate\Support\Sleep;
use RuntimeException;

#[Queue(QueueGroup::Databases)]
#[Tries(1)]
#[Timeout(7200)]
#[DeleteWhenMissingModels]
final class RebootDatabaseJob implements ShouldQueue
{
    use FinishesOperation, Queueable;

    private const int COME_BACK_MINUTES = 15;

    private const int POLL_SECONDS = 5;

    public function __construct(public Database $database) {}

    public function handle(RollingNodes $rolling, RefreshDatabaseStatus $refresh): void
    {
        $rolling->each($this->database, function (DatabaseNode $node, bool $expectPrimary) use ($rolling): void {
            $server = $node->server;
            $bootId = $server->bootId();
            $server->ssh($node->database)->exec('ssh.servers.os.reboot', log: "{$server->name}: rebooting");
            $polls = 0;

            while (rescue(fn (): string => $server->bootId(), $bootId, report: false) === $bootId) {
                if (++$polls > self::COME_BACK_MINUTES * 60 / self::POLL_SECONDS) {
                    throw new RuntimeException("{$server->name} did not come back within ".self::COME_BACK_MINUTES.' minutes of the reboot.');
                }

                Sleep::for(self::POLL_SECONDS)->seconds();
            }

            $rolling->backOnline($node, $expectPrimary);
        });

        $refresh->handle($this->database);
    }

    protected function operatingStatus(): DatabaseStatus
    {
        return DatabaseStatus::Rebooting;
    }

    protected function operationName(): string
    {
        return 'Reboot servers';
    }
}
