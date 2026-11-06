<?php

declare(strict_types=1);

namespace App\DatabaseProviders\Drivers\Shared;

use App\DatabaseProviders\Contracts\DatabaseDriver;
use App\DatabaseProviders\Groups\EngineArchive;
use App\DTOs\Databases\NodeFile;
use App\DTOs\Databases\Schedule;
use App\DTOs\Recovery\ArchiveLocation;
use App\Models\Database;
use App\Models\PointInTimeRecovery;
use App\Support\Scripts\Literal;

abstract readonly class BinlogArchive extends EngineArchive
{
    public const string ARCHIVE_SCHEDULE = 'binlog-archive';

    public const string BACKUP_SCHEDULE = 'binlog-backup';

    private const int ARCHIVE_EVERY_SECONDS = 60;

    private const int ARCHIVE_TIMEOUT_SECONDS = 3600;

    private const int BACKUP_TIMEOUT_SECONDS = 86400;

    public function __construct(
        DatabaseDriver $engine,
        Database $database,
        private string $root,
        private string $service,
        private string $client,
        private string $binlogTool,
        private string $backupTool,
        private string $streamTool,
        private string $binlogInfo,
        private string $gtidQuery,
    ) {
        parent::__construct($engine, $database);
    }

    /**
     * @return list<NodeFile>
     */
    public function files(PointInTimeRecovery $recovery): array
    {
        return [
            NodeFile::secret($this->environmentPath(), $this->environment($recovery->location(), [
                'RETENTION_DAYS' => (string) $recovery->retention_days,
            ]), 'root'),
            NodeFile::script($this->archiveScript(), view('ssh.databases.recovery.binlog.archive', $this->variables())->render()),
            NodeFile::script($this->backupScript(), view('ssh.databases.recovery.binlog.backup', $this->variables())->render()),
        ];
    }

    /**
     * @return list<Schedule>
     */
    public function schedules(): array
    {
        $recovery = $this->database->pointInTimeRecovery;

        if ($recovery === null) {
            return [];
        }

        return [
            Schedule::every(self::ARCHIVE_SCHEDULE, self::ARCHIVE_EVERY_SECONDS, [$this->archiveScript()])->timingOutAfter(self::ARCHIVE_TIMEOUT_SECONDS),
            Schedule::dailyAt(self::BACKUP_SCHEDULE, $this->backupTimeOf($recovery), [$this->backupScript()])->timingOutAfter(self::BACKUP_TIMEOUT_SECONDS),
        ];
    }

    public function variables(): array
    {
        return [
            'archiveWork' => $this->work(),
            'archiveEnvironment' => $this->environmentPath(),
            'archiveSecrets' => $this->environmentPath(),
            'archiveReplay' => $this->engine->scriptView('archive.replay'),
            'backupService' => self::BACKUP_SCHEDULE,
            'engineService' => $this->service,
            'client' => $this->client,
            'binlogTool' => $this->binlogTool,
            'backupTool' => $this->backupTool,
            'streamTool' => $this->streamTool,
            'binlogInfo' => $this->binlogInfo,
            'gtidQuery' => $this->gtidQuery,
        ];
    }

    public function restoreSource(ArchiveLocation $location): string
    {
        return $this->environment($location);
    }

    private function archiveScript(): string
    {
        return $this->work().'/bin/archive';
    }

    private function backupScript(): string
    {
        return $this->work().'/bin/backup';
    }

    private function work(): string
    {
        return "{$this->root}/archive";
    }

    private function environmentPath(): string
    {
        return "{$this->root}/conf/archive.env";
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function environment(ArchiveLocation $location, array $extra = []): string
    {
        $options = array_merge($location->rcloneEnvironment(), $extra);

        return view('ssh.databases.recovery.binlog.env', [
            'options' => array_map(Literal::shell(...), $options),
        ])->render();
    }
}
