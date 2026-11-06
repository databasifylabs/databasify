<?php

declare(strict_types=1);

namespace App\Actions\Databases\Recovery;

use App\DTOs\Databases\NodeFile;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Services\Databases\NodeRunner;

final readonly class SyncRecoveryFiles
{
    public function __construct(private NodeRunner $nodeRunner) {}

    public function handle(Database $database): void
    {
        $this->nodeRunner->each(
            $database->nodesWithServers(),
            fn (DatabaseNode $node) => $this->writeArchiveSettingsOn($database, $node),
            'syncing recovery files',
        );
    }

    private function writeArchiveSettingsOn(Database $database, DatabaseNode $node): void
    {
        $scriptData = $database->provider()->scriptData();

        $node->ssh()->exec('ssh.databases.recovery.sync', [
            ...$scriptData->for($node),
            ...$scriptData->archiving(),
            'files' => $this->recoveryFilesOf($database, $node),
        ], "{$node->server->name}: writing the archive settings");
    }

    /**
     * @return list<NodeFile>
     */
    private function recoveryFilesOf(Database $database, DatabaseNode $node): array
    {
        $engine = $database->provider();

        return [...$database->archiveFiles(), $engine->processes($node)->toNodeFile($engine->directory())];
    }
}
