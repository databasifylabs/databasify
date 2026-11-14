<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Services\Databases\RollingNodes;
use Illuminate\Support\Sleep;
use RuntimeException;

final readonly class RebootServers
{
    private const int COME_BACK_MINUTES = 15;

    private const int POLL_SECONDS = 5;

    public function __construct(
        private RollingNodes $rolling,
        private RefreshDatabaseStatus $refresh,
    ) {}

    public function handle(Database $database): void
    {
        $this->rolling->each($database, function (DatabaseNode $node, bool $expectPrimary): void {
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

            $this->rolling->backOnline($node, $expectPrimary);
        });

        $this->refresh->handle($database);
        $database->markReady();
    }
}
