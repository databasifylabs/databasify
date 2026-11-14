<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Postgres;

use App\DTOs\Recovery\ArchiveStatus;
use App\Engines\Contracts\Archive;
use App\Engines\Data\Operation;
use App\Engines\Data\Storage;
use Carbon\CarbonImmutable;

final readonly class PostgresArchive implements Archive
{
    private const int STATUS_TIMEOUT = 900;

    public function __construct(
        private PostgresDriver $driver,
        private PostgresFiles $files,
    ) {}

    public function status(bool $backUpNow): ArchiveStatus
    {
        return ArchiveStatus::fromPayload(($this->driver->read('engines.postgres.archive.status', $this->driver->data(), timeout: self::STATUS_TIMEOUT, env: ['BACK_UP_NOW' => $backUpNow ? 'true' : 'false'])));
    }

    public function restore(string $key, Storage $storage, CarbonImmutable $target): Operation
    {
        return $this->driver->task($key, 'engines.postgres.restore.start', $this->driver->data(), env: [
            'RESTORE_CONFIG' => $this->files->backrestConfig(PostgresFiles::repositoryOf($storage), null),
            'TARGET' => $target->utc()->format('Y-m-d H:i:s'),
            'LINE' => $this->driver->node->database->version_line,
        ]);
    }
}
