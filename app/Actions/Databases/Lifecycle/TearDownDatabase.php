<?php

declare(strict_types=1);

namespace App\Actions\Databases\Lifecycle;

use App\Actions\Databases\Domains\ClearDatabaseDns;
use App\Actions\Databases\Network\RemoveNodeFirewall;
use App\Actions\Databases\Nodes\StopDatabaseNode;
use App\Actions\Databases\Recovery\DeleteArchive;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Services\Databases\NodeRunner;
use Throwable;

final readonly class TearDownDatabase
{
    public function __construct(
        private RemoveNodeFirewall $firewall,
        private ClearDatabaseDns $dns,
        private DeleteArchive $archive,
        private NodeRunner $nodeRunner,
        private StopDatabaseNode $stop,
    ) {}

    public function handle(Database $database, bool $eraseData, bool $force, bool $deleteBackups = false): void
    {
        $nodes = $database->nodesWithServers();
        $this->nodeRunner->each(
            $nodes,
            fn (DatabaseNode $node) => $this->tearDown($database, $node, $eraseData, $force),
            "tearing down {$nodes->count()} nodes",
        );
        $this->clearDnsOf($database);

        if ($deleteBackups) {
            $this->deleteBackupsOf($database);
        }

        $database->delete();
    }

    private function tearDown(Database $database, DatabaseNode $node, bool $eraseData, bool $force): void
    {
        try {
            $this->stop->handle($database, $node, $eraseData, $this->stopLogFor($node, $eraseData));
            $this->firewall->handle($database, $node);
        } catch (Throwable $exception) {
            throw_unless($force, $exception);
            report($exception);
        }
    }

    private function stopLogFor(DatabaseNode $node, bool $eraseData): string
    {
        return $eraseData ? "{$node->server->name}: stopping and erasing data" : "{$node->server->name}: stopping";
    }

    private function deleteBackupsOf(Database $database): void
    {
        $recovery = $database->pointInTimeRecovery;

        if ($recovery === null) {
            return;
        }

        try {
            $this->archive->handle($recovery->storedArchive());
        } catch (Throwable $exception) {
            $this->logFailureOn($database, 'Delete point-in-time backups', "Could not delete the backups under {$recovery->path}, delete them by hand: {$exception->getMessage()}");
        }
    }

    private function clearDnsOf(Database $database): void
    {
        try {
            $this->dns->handle($database);
        } catch (Throwable $exception) {
            $this->logFailureOn($database, 'Remove DNS records', "Could not remove the Cloudflare records, delete them by hand: {$exception->getMessage()}");
        }
    }

    private function logFailureOn(Database $database, string $step, string $message): void
    {
        $database->seed()->server->logFailure($step, $message, $database);
    }
}
