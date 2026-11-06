<?php

declare(strict_types=1);

namespace App\DatabaseProviders\Groups;

use App\DTOs\Databases\NodeFile;
use App\DTOs\Recovery\ArchiveLocation;
use App\DTOs\Recovery\ArchiveStatus;
use App\DTOs\Recovery\RecoverySource;
use App\Models\DatabaseNode;
use App\Models\PointInTimeRecovery;
use App\Services\Ssh\Ssh;

abstract readonly class EngineArchive extends EngineGroup
{
    /**
     * @return list<NodeFile>
     */
    abstract public function files(PointInTimeRecovery $recovery): array;

    /**
     * @return array<string, mixed>
     */
    abstract public function variables(): array;

    abstract public function restoreSource(ArchiveLocation $location): string;

    public function restore(RecoverySource $source): void
    {
        $seed = $this->database->seed();
        $log = "{$seed->server->name}: restoring {$source->database} as it was at {$source->targetForTools()} UTC";

        $this->runStep($seed, 'archive.restore', $this->scriptData()->restoring($seed, $source), $log, Ssh::LONG_TIMEOUT);
    }

    public function status(DatabaseNode $primary, bool $backUpNow): ArchiveStatus
    {
        return ArchiveStatus::fromOutput($this->runStep($primary, 'archive.status', [
            ...$this->scriptData()->for($primary),
            ...$this->scriptData()->archiving(),
            'backUpNow' => $backUpNow,
        ]));
    }

    protected function backupTimeOf(PointInTimeRecovery $recovery): string
    {
        return sprintf('%02d:00', $recovery->backup_hour);
    }
}
