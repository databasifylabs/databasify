<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MySqlFamily;

use App\Engines\Data\Storage;
use App\Engines\Payload;
use App\Engines\Requests\ArchiveStatus;
use App\Engines\Requests\RestoreStart;
use App\Engines\Responses\ArchiveStatus as ArchiveStatusReply;
use App\Engines\Responses\Operation;
use App\Exceptions\Engines\EngineUnsupported;
use App\Models\DatabaseNode;
use Carbon\CarbonImmutable;

final readonly class Archive
{
    private const string INFO_SUFFIX = '.binlog-info';

    private const string STAMP_FORMAT = 'Ymd\THis\Z';

    public function __construct(
        private FamilyScripts $scripts,
        private Configuration $configuration,
    ) {}

    public function status(DatabaseNode $node, ArchiveStatus $request): ArchiveStatusReply
    {
        $engine = FamilyScripts::engineOf($node);
        $archive = $this->configuration->savedRequestOf($node)->archive ?? throw new EngineUnsupported('unsupported', 'this engine does not implement "archive status"');
        $backupUnit = $engine->archiveUnits()['backup'];

        $reply = $this->scripts->run($node, 'mysql-family/archive/status', [
            ...self::storageEnvOf($archive->storage),
            'SUFFIX' => $engine->baseSuffix(),
            'BACKUP_UNIT' => $backupUnit,
            'BACK_UP_NOW' => $request->backUpNow ? 'true' : 'false',
        ], timeout: 900)->json();

        $bases = $this->basesIn($reply->objects('base'), $engine->baseSuffix());
        $latest = $bases === [] ? null : end($bases);
        $shipped = $this->latestOf($reply->strings('shipped'));

        return new ArchiveStatusReply(
            count($bases),
            $bases === [] ? null : $this->timeOf($bases[0]),
            $latest === null ? null : $this->timeOf($shipped !== null && $shipped->greaterThan($latest) ? $shipped : $latest),
            $latest === null ? null : $this->timeOf($latest),
            in_array($reply->string('backupResult'), ['', 'success'], true) ? null : "The last base backup failed, see the {$backupUnit} log.",
        );
    }

    public function restore(DatabaseNode $node, RestoreStart $request): Operation
    {
        $target = $request->target->utc();

        return $this->scripts->start($node, $request->key, '{engine}/restore/start', [
            ...self::storageEnvOf($request->storage),
            'SUFFIX' => FamilyScripts::engineOf($node)->baseSuffix(),
            'TARGET' => $target->format('Y-m-d H:i:s'),
            'TARGET_STAMP' => $target->format(self::STAMP_FORMAT),
            'TARGET_RFC3339' => $this->timeOf($target),
        ]);
    }

    /**
     * @return array{RCLONE_REMOTE: string, STORAGE_PATH: string, RCLONE_S3_ACCESS_KEY_ID: string, RCLONE_S3_SECRET_ACCESS_KEY: string, RCLONE_CONFIG: string}
     */
    public static function storageEnvOf(Storage $storage): array
    {
        return [
            'RCLONE_REMOTE' => sprintf(':s3,provider=Other,endpoint="%s",region="%s",force_path_style=%s,no_check_bucket=true:%s/', $storage->endpoint, $storage->region, $storage->pathStyle ? 'true' : 'false', $storage->bucket),
            'STORAGE_PATH' => trim($storage->path, '/'),
            'RCLONE_S3_ACCESS_KEY_ID' => $storage->accessKey,
            'RCLONE_S3_SECRET_ACCESS_KEY' => $storage->secretKey,
            'RCLONE_CONFIG' => '/dev/null',
        ];
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
            $name = str_ends_with($path, $suffix) ? substr($path, 0, -strlen($suffix)) : null;
            $stamp = $name === null || ! in_array($name.self::INFO_SUFFIX, $paths, true) ? false : CarbonImmutable::createFromFormat(self::STAMP_FORMAT, $name, 'UTC');

            if ($stamp instanceof CarbonImmutable) {
                $bases[] = $stamp;
            }
        }

        usort($bases, fn (CarbonImmutable $a, CarbonImmutable $b): int => $a <=> $b);

        return $bases;
    }

    /**
     * @param  list<string>  $times
     */
    private function latestOf(array $times): ?CarbonImmutable
    {
        $latest = null;

        foreach ($times as $time) {
            $parsed = CarbonImmutable::parse($time)->utc();
            $latest = $latest === null || $parsed->greaterThan($latest) ? $parsed : $latest;
        }

        return $latest;
    }

    private function timeOf(CarbonImmutable $time): string
    {
        $fraction = rtrim($time->format('u'), '0');

        return $time->utc()->format('Y-m-d\TH:i:s').($fraction === '' ? '' : ".{$fraction}").'Z';
    }
}
