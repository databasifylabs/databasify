<?php

declare(strict_types=1);

namespace App\DatabaseProviders\Drivers\Mongodb;

use App\DatabaseProviders\Groups\EngineArchive;
use App\DTOs\Databases\NodeFile;
use App\DTOs\Databases\ProcessSpec;
use App\DTOs\Databases\Schedule;
use App\DTOs\Recovery\ArchiveLocation;
use App\Models\PointInTimeRecovery;

final readonly class MongodbArchive extends EngineArchive
{
    public const string AGENT_UNIT = 'pbm-agent';

    public const string BACKUP_SCHEDULE = 'mongodb-backup';

    public const string WORK = Mongodb::ROOT.'/archive';

    public const string PATH = self::WORK.'/pbm.json';

    public const string RESTORE_PATH = self::WORK.'/restore.json';

    public const string PASSWORD = Mongodb::ROOT.'/conf/pbm-password';

    private const string BACKUP_SCRIPT = self::WORK.'/bin/backup';

    private const int BACKUP_TIMEOUT_SECONDS = 86400;

    private const int AGENT_RESTART_SECONDS = 5;

    /**
     * @return list<NodeFile>
     */
    public function files(PointInTimeRecovery $recovery): array
    {
        return [
            NodeFile::secret(self::PATH, $this->config($recovery->location(), slicesOplog: true), 'root'),
            NodeFile::script(self::BACKUP_SCRIPT, $this->backupScriptFor($recovery), 'root'),
        ];
    }

    public function agentProcess(): ProcessSpec
    {
        return ProcessSpec::daemon(self::AGENT_UNIT, $this->agentCommand(), 'mongodb')
            ->after(Mongodb::SERVER_UNIT)
            ->restartingAfter(self::AGENT_RESTART_SECONDS);
    }

    public function backupSchedule(PointInTimeRecovery $recovery): Schedule
    {
        return Schedule::dailyAt(self::BACKUP_SCHEDULE, $this->backupTimeOf($recovery), [self::BACKUP_SCRIPT], 'root')
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
            'pbmUri' => $this->shellUri(),
        ];
    }

    public function restoreSource(ArchiveLocation $location): string
    {
        return $this->config($location);
    }

    private function shellUri(): string
    {
        return 'mongodb://pbm:$(cat '.self::PASSWORD.')@$(cat '.Mongodb::OWN_ADDRESS.')/?authSource=admin&tls=true&tlsCAFile='.Mongodb::ROOT.'/tls/ca.crt';
    }

    private function backupScriptFor(PointInTimeRecovery $recovery): string
    {
        return view($this->engine->scriptView('archive.backup'), [
            ...$this->scriptData()->for(),
            ...$this->variables(),
            'retentionDays' => $recovery->retention_days,
        ])->render();
    }

    /**
     * @return list<string>
     */
    private function agentCommand(): array
    {
        return ['sh', '-c', trim(view($this->engine->scriptView('processes.pbm-agent'), ['uri' => $this->shellUri()])->render())];
    }

    private function config(ArchiveLocation $location, bool $slicesOplog = false): string
    {
        return view($this->engine->scriptView('archive.config'), [
            'storage' => json_encode($location->pbmStorage(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'slicesOplog' => $slicesOplog,
        ])->render();
    }
}
