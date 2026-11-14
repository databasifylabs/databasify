<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MongoDb;

use App\DTOs\Recovery\ArchiveStatus;
use App\Engines\Contracts\Archive;
use App\Engines\Data\Operation;
use App\Engines\Data\Storage;
use App\Exceptions\Engines\EngineBusy;
use App\Exceptions\Engines\EngineUnsupported;
use Carbon\CarbonImmutable;

final readonly class MongoDbArchive implements Archive
{
    public function __construct(
        private MongoDbDriver $driver,
        private MongoDbConfiguration $configuration,
    ) {}

    public function status(bool $backUpNow): ArchiveStatus
    {
        $saved = $this->configuration->savedRequestOf();

        if ($saved->archive === null || $saved->secrets->agent === null) {
            throw new EngineUnsupported('unsupported', 'this engine does not implement "archive status"');
        }

        $reply = $this->driver->read('engines.mongodb.archive.status', timeout: 900, env: [
            'PBM_CONFIG' => MongoDbFiles::pbmConfigOf($saved->archive->storage, true),
            'PBM_MONGODB_URI' => MongoDbFiles::pbmUriOf($saved->secrets->agent),
        ]);
        $failed = ! in_array($reply->string('backupResult'), ['', 'success'], true);
        $snapshots = $reply->ints('snapshots');
        $window = RestorableWindow::of($snapshots, array_map(OplogRange::fromPayload(...), $reply->objects('chunks')));
        $status = new ArchiveStatus(
            count($snapshots),
            $this->momentOf($window->earliest),
            $this->momentOf($window->latest),
            $this->momentOf($snapshots === [] ? null : max($snapshots)),
            $failed ? 'The last backup failed, see the '.MongoDbFiles::BACKUP_UNIT.' log.' : $reply->nullableString('problem'),
        );

        if ($backUpNow || ($status->backups === 0 && $status->problem === null)) {
            $this->driver->run('engines.mongodb.archive.back-up');
        }

        return $status;
    }

    public function restore(string $key, Storage $storage, CarbonImmutable $target): Operation
    {
        $saved = $this->configuration->savedRequestOf();

        if ($saved->archive === null || $saved->secrets->agent === null) {
            throw new EngineBusy('busy', 'configure archive on this database before restoring into it');
        }

        return $this->driver->task($key, 'engines.mongodb.restore.start', env: [
            'PBM_MONGODB_URI' => MongoDbFiles::pbmUriOf($saved->secrets->agent),
            'RESTORE_CONFIG' => MongoDbFiles::pbmConfigOf($storage, false),
            'SAVED_CONFIG' => MongoDbFiles::pbmConfigOf($saved->archive->storage, false),
            'TARGET' => $target->utc()->format('Y-m-d\TH:i:s'),
            'ADMIN_PASSWORD' => (string) $saved->secrets->admin,
            'AGENT_PASSWORD' => $saved->secrets->agent,
        ]);
    }

    private function momentOf(?int $seconds): ?CarbonImmutable
    {
        return $seconds === null ? null : CarbonImmutable::createFromTimestampUTC($seconds);
    }
}
