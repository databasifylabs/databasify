<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Models\Database;
use App\Models\DatabaseNode;
use App\Models\Server;
use Illuminate\Support\Sleep;
use RuntimeException;

final readonly class RebootDatabaseNode
{
    private const int POLL_SECONDS = 5;

    private const int MAX_POLLS = 180;

    private const int BOOT_ID_TIMEOUT_SECONDS = 30;

    private const int CONTAINER_POLLS = 12;

    public function handle(Database $database, DatabaseNode $node, bool $expectPrimary): void
    {
        $server = $node->server;
        $bootId = $this->bootIdOf($server);

        $server->ssh($database)->exec('ssh.servers.os.reboot', log: "{$server->name}: rebooting");
        $this->untilRebooted($server, $bootId);
        $this->untilContainerRuns($database, $server);

        $database->provider()->nodes()->waitUntilReady($node, $expectPrimary, "{$server->name}: back, waiting until it serves again");
    }

    private function untilRebooted(Server $server, string $previousBootId): void
    {
        for ($poll = 1; $poll <= self::MAX_POLLS; $poll++) {
            Sleep::for(self::POLL_SECONDS)->seconds();
            $bootId = rescue(fn (): string => $this->bootIdOf($server), '', report: false);

            if ($bootId !== '' && $bootId !== $previousBootId) {
                return;
            }
        }

        throw new RuntimeException("{$server->name} did not come back within ".(self::POLL_SECONDS * self::MAX_POLLS / 60).' minutes of the reboot.');
    }

    private function untilContainerRuns(Database $database, Server $server): void
    {
        for ($poll = 1; $poll <= self::CONTAINER_POLLS; $poll++) {
            if ($this->containerRunsOn($database, $server)) {
                return;
            }

            Sleep::for(self::POLL_SECONDS)->seconds();
        }

        throw new RuntimeException("{$database->container()} did not start on {$server->name} within ".(self::POLL_SECONDS * self::CONTAINER_POLLS).' seconds of the reboot.');
    }

    private function containerRunsOn(Database $database, Server $server): bool
    {
        return rescue(function () use ($database, $server): bool {
            $server->ssh($database)->exec('ssh.databases.containers.running', ['container' => $database->container()]);

            return true;
        }, false, report: false);
    }

    private function bootIdOf(Server $server): string
    {
        return trim($server->ssh()->exec('ssh.servers.os.boot-id', timeout: self::BOOT_ID_TIMEOUT_SECONDS));
    }
}
