<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Postgres;

use App\DTOs\Recovery\ArchiveStatus;
use App\Engines\Contracts\Archive;
use App\Engines\Data\Operation;
use App\Engines\Data\Storage;
use App\Engines\Payload;
use Carbon\CarbonImmutable;

final readonly class PostgresArchive implements Archive
{
    private const int STATUS_TIMEOUT = 900;

    private const int MISSING_STANZA_STATUS = 1;

    private const array UNREPORTED_STATUSES = [0, 2, 6];

    public function __construct(
        private PostgresDriver $driver,
        private PostgresFiles $files,
        private PostgresConfiguration $configuration,
    ) {}

    public function status(bool $backUpNow): ArchiveStatus
    {
        if ($this->configuration->savedRequestOf()->archive === null) {
            return ArchiveStatus::unreadable('point-in-time recovery is not configured on this node');
        }

        $reply = $this->driver->read('engines.postgres.archive.status', timeout: self::STATUS_TIMEOUT);

        if ($reply->has('error')) {
            return ArchiveStatus::unreadable('pgBackRest could not read the archive: pgbackrest --config='.PostgresFiles::CONF.'/pgbackrest.conf: '.$reply->string('error'));
        }

        $stanzas = json_decode($reply->string('info'), true);

        if (! is_array($stanzas) || ! is_array($stanzas[0] ?? null)) {
            return ArchiveStatus::unreadable('pgBackRest could not read the archive: unreadable pgBackRest info');
        }

        $stanza = new Payload($stanzas[0]);
        $bases = collect($stanza->has('backup') ? $stanza->objects('backup') : [])
            ->map(fn (Payload $backup): int => $backup->object('timestamp')->int('stop'))
            ->sort()
            ->values();
        $earliest = $bases->first();
        $lastBackup = $bases->last();
        $status = $stanza->object('status');
        $statusCode = $status->int('code');
        $missingStanza = $statusCode === self::MISSING_STANZA_STATUS;
        $backupResult = $reply->string('backupResult');
        $backupFailed = ! in_array($backupResult, ['', 'success'], true);
        $archiveFailure = $reply->string('archiveFailure');

        $failure = match (true) {
            $missingStanza => null,
            $archiveFailure !== '' => $archiveFailure,
            ! in_array($statusCode, self::UNREPORTED_STATUSES, true) => 'pgBackRest reports: '.$status->string('message'),
            default => null,
        };

        if ($failure === null && $backupFailed) {
            $failure = "The last base backup stopped with {$backupResult}, see the ".PostgresManifest::BACKUP_UNIT.' log.';
        }

        $startBackup = $backUpNow || ($bases->isEmpty() && ! $backupFailed);

        if ($missingStanza || $startBackup) {
            $this->driver->run('engines.postgres.archive.back-up', ['createStanza' => $missingStanza, 'startBackup' => $startBackup]);
        }

        $latest = max($this->archivedUntil($reply->strings('heartbeats'), $reply->string('lastArchivedWal'), $earliest), $lastBackup);

        return new ArchiveStatus($bases->count(), $this->timeOf($earliest), $this->timeOf($latest), $this->timeOf($lastBackup), $failure);
    }

    public function restore(string $key, Storage $storage, CarbonImmutable $target): Operation
    {
        return $this->driver->task($key, 'engines.postgres.restore.start', [
            'target' => $target->utc()->format('Y-m-d H:i:s'),
            'units' => PostgresManifest::unitsOf($this->configuration->savedRequestOf()),
        ], env: ['RESTORE_CONFIG' => $this->files->backrestConfig(PostgresFiles::repositoryOf($storage), null)]);
    }

    /**
     * @param  list<string>  $heartbeats
     */
    private function archivedUntil(array $heartbeats, string $lastArchivedWal, ?int $earliest): ?int
    {
        if ($earliest === null || preg_match('/^[0-9A-F]{24}/', $lastArchivedWal) !== 1) {
            return null;
        }

        $archivedSegment = substr($lastArchivedWal, 8, 16);
        $wholeSegment = strlen($lastArchivedWal) === 24;

        $lastCovered = collect($heartbeats)
            ->map(fn (string $heartbeat): array => preg_split('/\s+/', trim($heartbeat)) ?: [])
            ->filter(fn (array $heartbeat): bool => count($heartbeat) >= 2 && strlen($heartbeat[1]) >= 24 && (int) $heartbeat[0] >= $earliest)
            ->filter(fn (array $heartbeat): bool => strcmp(substr($heartbeat[1], 8), $archivedSegment) < 0 || ($wholeSegment && substr($heartbeat[1], 8) === $archivedSegment))
            ->map(fn (array $heartbeat): int => (int) $heartbeat[0])
            ->max();

        return is_int($lastCovered) ? $lastCovered : null;
    }

    private function timeOf(?int $epoch): ?CarbonImmutable
    {
        return $epoch === null ? null : CarbonImmutable::createFromTimestampUTC($epoch);
    }
}
