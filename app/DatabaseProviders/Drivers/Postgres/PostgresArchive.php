<?php

declare(strict_types=1);

namespace App\DatabaseProviders\Drivers\Postgres;

use App\DatabaseProviders\Groups\EngineArchive;
use App\DTOs\Databases\NodeFile;
use App\DTOs\Databases\Schedule;
use App\DTOs\Recovery\ArchiveLocation;
use App\Models\PointInTimeRecovery;
use App\Support\Databases\ClientPorts;

final readonly class PostgresArchive extends EngineArchive
{
    public const string BACKUP_SCHEDULE = 'databasify-postgres-backup';

    public const string STANZA = 'main';

    public const string PATH = Postgres::ROOT.'/conf/pgbackrest.conf';

    public const string RESTORE_PATH = Postgres::ROOT.'/conf/pgbackrest-restore.conf';

    public const string WORK = Postgres::ROOT.'/archive';

    private const string USER = 'postgres';

    private const string BACKUP_SCRIPT = self::WORK.'/bin/backup';

    private const int BACKUP_TIMEOUT_SECONDS = 86400;

    public static function archiveCommand(): string
    {
        return 'pgbackrest --config='.self::PATH.' --stanza='.self::STANZA.' archive-push %p';
    }

    /**
     * @return list<NodeFile>
     */
    public function files(PointInTimeRecovery $recovery): array
    {
        return [
            NodeFile::secret(self::PATH, $this->config($recovery->location(), $recovery->retention_days), self::USER),
            NodeFile::script(self::BACKUP_SCRIPT, view($this->engine->scriptView('archive.backup'), $this->variables())->render(), self::USER),
        ];
    }

    public function backupSchedule(PointInTimeRecovery $recovery): Schedule
    {
        return Schedule::dailyAt(self::BACKUP_SCHEDULE, $this->backupTimeOf($recovery), [self::BACKUP_SCRIPT], self::USER)
            ->timingOutAfter(self::BACKUP_TIMEOUT_SECONDS);
    }

    public function variables(): array
    {
        return [
            'archiveWork' => self::WORK,
            'archiveConfig' => self::PATH,
            'archiveSecrets' => self::PATH,
            'restoreConfig' => self::RESTORE_PATH,
            'backupService' => self::BACKUP_SCHEDULE,
            'stanza' => self::STANZA,
            'socketDirectory' => Postgres::SOCKET_DIRECTORY,
            'clientPort' => ClientPorts::CLIENT,
        ];
    }

    public function restoreSource(ArchiveLocation $location): string
    {
        return $this->config($location);
    }

    private function config(ArchiveLocation $location, ?int $retentionDays = null): string
    {
        return view($this->engine->scriptView('archive.config'), [
            'repository' => $location->pgbackrestRepository(),
            'retentionDays' => $retentionDays,
            'port' => ClientPorts::CLIENT,
        ])->render();
    }
}
