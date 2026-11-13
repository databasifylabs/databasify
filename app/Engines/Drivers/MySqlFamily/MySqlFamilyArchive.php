<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MySqlFamily;

use App\DTOs\Recovery\ArchiveStatus;
use App\Engines\Contracts\Archive;
use App\Engines\Data\Operation;
use App\Engines\Data\Storage;
use App\Engines\Payload;
use App\Engines\Responses\ArchiveStatus as ArchiveStatusReply;
use App\Exceptions\Engines\EngineUnsupported;
use Carbon\CarbonImmutable;

final readonly class MySqlFamilyArchive implements Archive
{
    private const string INFO_SUFFIX = '.binlog-info';

    private const string STAMP_FORMAT = 'Ymd\THis\Z';

    public function __construct(
        private MySqlFamilyDriver $driver,
        private FamilyScripts $scripts,
        private MySqlFamilyConfiguration $configuration,
    ) {}

    public function status(bool $backUpNow): ArchiveStatus
    {
        $engine = $this->driver->engine();
        $archive = $this->configuration->savedRequestOf()->archive ?? throw new EngineUnsupported('unsupported', 'this engine does not implement "archive status"');
        $backupUnit = $engine->archiveUnits()['backup'];

        $reply = $this->scripts->read($this->driver, 'mysql-family/archive/status', [
            ...FamilyFiles::storageEnvOf($archive->storage),
            'SUFFIX' => $engine->baseSuffix(),
            'BACKUP_UNIT' => $backupUnit,
            'BACK_UP_NOW' => $backUpNow ? 'true' : 'false',
        ], timeout: 900);

        $bases = $this->basesIn($reply->objects('base'), $engine->baseSuffix());
        $shipped = $this->latestOf($reply->strings('shipped'));
        $failure = in_array($reply->string('backupResult'), ['', 'success'], true) ? null : "The last base backup failed, see the {$backupUnit} log.";

        if ($bases === []) {
            return ArchiveStatus::fromEngine(new ArchiveStatusReply(0, null, null, null, $failure));
        }

        $latest = end($bases);
        $recoverableUntil = $shipped !== null && $shipped->greaterThan($latest) ? $shipped : $latest;

        return ArchiveStatus::fromEngine(new ArchiveStatusReply(count($bases), $this->timeOf($bases[0]), $this->timeOf($recoverableUntil), $this->timeOf($latest), $failure));
    }

    public function restore(string $key, Storage $storage, CarbonImmutable $target): Operation
    {
        $target = $target->utc();

        return $this->scripts->start($this->driver, $key, '{engine}/restore/start', [
            ...FamilyFiles::storageEnvOf($storage),
            'SUFFIX' => $this->driver->engine()->baseSuffix(),
            'TARGET' => $target->format('Y-m-d H:i:s'),
            'TARGET_STAMP' => $target->format(self::STAMP_FORMAT),
            'TARGET_RFC3339' => $this->timeOf($target),
        ]);
    }

    /**
     * @param  list<Payload>  $entries
     * @return list<CarbonImmutable>
     */
    private function basesIn(array $entries, string $suffix): array
    {
        $paths = array_map(fn (Payload $entry): string => $entry->string('Path'), $entries);
        $bases = [];

        foreach ($paths as $path) {
            $stamp = $this->baseStampOf($path, $suffix, $paths);

            if ($stamp !== null) {
                $bases[] = $stamp;
            }
        }

        usort($bases, fn (CarbonImmutable $a, CarbonImmutable $b): int => $a <=> $b);

        return $bases;
    }

    /**
     * @param  list<string>  $paths
     */
    private function baseStampOf(string $path, string $suffix, array $paths): ?CarbonImmutable
    {
        if (! str_ends_with($path, $suffix)) {
            return null;
        }

        $name = substr($path, 0, -strlen($suffix));

        if (! in_array($name.self::INFO_SUFFIX, $paths, true)) {
            return null;
        }

        $stamp = CarbonImmutable::createFromFormat(self::STAMP_FORMAT, $name, 'UTC');

        return $stamp instanceof CarbonImmutable ? $stamp : null;
    }

    /**
     * @param  list<string>  $times
     */
    private function latestOf(array $times): ?CarbonImmutable
    {
        $latest = null;

        foreach ($times as $time) {
            $parsed = CarbonImmutable::parse($time)->utc();

            if ($latest === null || $parsed->greaterThan($latest)) {
                $latest = $parsed;
            }
        }

        return $latest;
    }

    private function timeOf(CarbonImmutable $time): string
    {
        $fraction = rtrim($time->format('u'), '0');

        return $time->utc()->format('Y-m-d\TH:i:s').($fraction === '' ? '' : ".{$fraction}").'Z';
    }
}
