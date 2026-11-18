<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MongoDb;

use App\DTOs\Recovery\ArchiveStatus;
use App\Engines\Contracts\Archive;
use App\Engines\Data\Operation;
use App\Engines\Data\Storage;
use App\Engines\Payload;
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
            throw new EngineUnsupported('this engine does not implement "archive status"');
        }

        $reply = $this->driver->read('engines.mongodb.archive.status', timeout: 900, env: [
            'PBM_CONFIG' => MongoDbFiles::pbmConfigOf($saved->archive->storage, true),
            'PBM_MONGODB_URI' => MongoDbFiles::pbmUriOf($saved->secrets->agent),
        ]);
        $pbm = $reply->object('pbm');
        $backups = $pbm->nullableObject('backups');
        $snapshots = $this->snapshotsIn($backups);
        $chunks = array_map(
            fn (Payload $chunk): OplogRange => OplogRange::fromPayload($chunk->object('range')),
            $backups?->nullableObject('pitrChunks')?->optionalObjects('pitrChunks') ?? [],
        );
        $window = RestorableWindow::of($snapshots, $chunks);
        $failed = ! in_array($reply->string('backupResult'), ['', 'success'], true);
        $status = new ArchiveStatus(
            count($snapshots),
            $this->momentOf($window->earliest),
            $this->momentOf($window->latest),
            $this->momentOf($snapshots === [] ? null : max($snapshots)),
            $failed ? 'The last backup failed, see the '.MongoDbFiles::BACKUP_UNIT.' log.' : $this->problemOf($pbm),
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
            throw new EngineBusy('configure archive on this database before restoring into it');
        }

        $admin = $saved->secrets->admin;

        return $this->driver->task($key, 'engines.mongodb.restore.start', [
            'target' => $target->utc()->format('Y-m-d\TH:i:s'),
            'savesUsers' => $admin !== null,
        ], [
            'PBM_MONGODB_URI' => MongoDbFiles::pbmUriOf($saved->secrets->agent),
            'RESTORE_CONFIG' => MongoDbFiles::pbmConfigOf($storage, false),
            ...($admin === null ? [] : ['ADMIN_PASSWORD' => $admin, 'AGENT_PASSWORD' => $saved->secrets->agent]),
        ]);
    }

    /**
     * @return list<int>
     */
    private function snapshotsIn(?Payload $backups): array
    {
        return array_values(collect($backups?->optionalObjects('snapshot') ?? [])
            ->filter(fn (Payload $snapshot): bool => $snapshot->nullableString('status') === 'done')
            ->map(fn (Payload $snapshot): int => max($snapshot->nullableInt('restoreTo') ?? 0, $snapshot->nullableInt('completeTS') ?? 0))
            ->all());
    }

    private function problemOf(Payload $pbm): ?string
    {
        $error = $pbm->nullableObject('pitr')?->nullableString('error');

        return $error === '' ? null : $error;
    }

    private function momentOf(?int $seconds): ?CarbonImmutable
    {
        return $seconds === null ? null : CarbonImmutable::createFromTimestampUTC($seconds);
    }
}
