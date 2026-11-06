<?php

declare(strict_types=1);

namespace App\DatabaseProviders\Drivers\Mysql;

use App\DatabaseProviders\Drivers\Shared\BinlogMigration;
use App\DTOs\Migrations\MigrationProgress;
use App\DTOs\Migrations\MigrationSource;
use App\DTOs\Migrations\SourceForm;
use App\DTOs\Migrations\SourceReport;
use App\Models\DatabaseMigration;
use App\Services\Ssh\Ssh;
use App\Support\Migrations\NameList;
use App\Support\Scripts\Literal;
use App\Support\Ssh\KeyValueOutput;

final readonly class MysqlMigration extends BinlogMigration
{
    private const string STATE_DIR = '/srv/mysql/migration';

    private const int REPLICATION_PASSWORD_MAX_LENGTH = 32;

    private const int MINIMUM_BINLOG_RETENTION_SECONDS = 1209600;

    private const string ROLE_READ_GRANT = '/GRANT [^`]*\bSELECT\b[^`]* ON (\*\.\*|`mysql`\.(\*|`%s`)) /';

    public function form(): SourceForm
    {
        return new SourceForm(3306, credentialsRequired: true, selectsDatabases: true, reservedDatabases: ['mysql', 'sys', 'performance_schema', 'information_schema']);
    }

    public function check(MigrationSource $source): SourceReport
    {
        $output = $this->database->primaryOrSeed()->ssh()->exec($this->engine->scriptView('migrations.check'), [
            ...$this->workspaceIn(self::STATE_DIR),
            'password' => $source->password,
            'config' => $this->configOf($source),
        ]);
        $report = $this->reportIn($output);

        if (($report['reachable'] ?? false) !== true) {
            return SourceReport::unreachable($this->textOf($report['error'] ?? self::NO_ANSWER));
        }

        $grants = $this->textOf($report['grants'] ?? '');

        return new SourceReport(true, $this->blockersFor($report, $grants, $source), [
            'version' => $this->textOf($report['version'] ?? ''),
            'size_mb' => $this->textOf($report['size_mb'] ?? '0'),
            'gtid_mode' => $this->textOf($report['gtid_mode'] ?? ''),
            'binlog_format' => $this->textOf($report['binlog_format'] ?? ''),
            'consistent_snapshot' => $this->canSnapshot($grants) ? 'yes' : 'no',
        ], occupied: $this->occupiedIn($output), problems: $this->problemsFor($report, $grants, $source), warnings: $this->warningsFor($report), databases: NameList::from($report['schemas'] ?? null));
    }

    public function start(DatabaseMigration $migration): void
    {
        $node = $migration->host();
        $source = $migration->source;
        $report = $this->check($source);

        $node->ssh()->exec($this->engine->scriptView('migrations.copy'), [
            ...$this->workspaceIn(self::STATE_DIR),
            'password' => $source->password,
            'address' => $source->address(),
            'config' => $this->dumpConfigOf($source, $this->isContinuous($migration) || $report->fact('consistent_snapshot') === 'yes'),
        ], "{$node->server->name}: starting the copy from {$source->address()}");
    }

    public function progress(DatabaseMigration $migration): MigrationProgress
    {
        $values = KeyValueOutput::parse($migration->ssh()->exec($this->engine->scriptView('migrations.progress'), [
            ...$this->workspaceIn(self::STATE_DIR),
            'refilter' => $this->refilterFor($migration->source),
        ]));

        return match (true) {
            isset($values['copy_exit']) => $this->copyResultFrom($migration, $values),
            ($values['copy'] ?? '') === 'running' => MigrationProgress::working('Copying: '.($values['log'] ?? '')),
            ($values['copy'] ?? '') === 'lost' => MigrationProgress::broken('The copy stopped unexpectedly: '.($values['log'] ?? '')),
            default => $this->replicationFrom($values),
        };
    }

    public function follow(DatabaseMigration $migration): void
    {
        $position = $migration->position;
        $file = $position->file ?? '';
        $offset = $position->position ?? '';

        $this->ensureReplicableFrom($file, $offset);

        $migration->ssh()->exec($this->engine->scriptView('migrations.replicate'), [
            ...$this->scriptData()->for(),
            'stateDir' => self::STATE_DIR,
            'sql' => $this->channelFor($migration->source, $file, $offset),
        ], timeout: Ssh::TIMEOUT);
    }

    public function stop(DatabaseMigration $migration): void
    {
        $migration->ssh()->exec($this->engine->scriptView('migrations.stop'), $this->workspaceIn(self::STATE_DIR), timeout: self::STOP_TIMEOUT);
    }

    /**
     * @param  array<string, string>  $values
     */
    private function copyResultFrom(DatabaseMigration $migration, array $values): MigrationProgress
    {
        if ($values['copy_exit'] !== '0') {
            return MigrationProgress::broken('The copy failed: '.($values['log'] ?? ''));
        }

        $decoded = json_decode($values['position'] ?? '', true);
        $migration->update(['position' => $migration->position->withCoordinates(
            is_array($decoded) ? $this->textOf($decoded['file'] ?? '') : '',
            is_array($decoded) ? $this->textOf($decoded['position'] ?? '') : '',
        )]);

        return MigrationProgress::caughtUp('Copied every table from the source.');
    }

    /**
     * @param  array<string, string>  $values
     */
    private function replicationFrom(array $values): MigrationProgress
    {
        if ($values === []) {
            return MigrationProgress::broken('Neither the copy nor the replication channel is running.');
        }

        $running = ($values['Replica_IO_Running'] ?? '') === 'Yes' && ($values['Replica_SQL_Running'] ?? '') === 'Yes';

        return $this->replicationProgressOf($values, $running, $values['Seconds_Behind_Source'] ?? '', 'The migration channel is reconnecting to the source.');
    }

    private function configOf(MigrationSource $source): string
    {
        return json_encode($this->connectionOf($source), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function dumpConfigOf(MigrationSource $source, bool $consistent): string
    {
        return json_encode(array_merge($this->connectionOf($source), [
            'dumpDir' => self::STATE_DIR.'/dump',
            'consistent' => $consistent,
        ]), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{host: string, port: int, user: string, tls: bool, databases: list<string>}
     */
    private function connectionOf(MigrationSource $source): array
    {
        return [
            'host' => $source->host,
            'port' => $source->port,
            'user' => $source->username,
            'tls' => $source->tls,
            'databases' => $source->databases,
        ];
    }

    /**
     * @param  array<mixed>  $report
     * @return list<string>
     */
    private function blockersFor(array $report, string $grants, MigrationSource $source): array
    {
        return array_values(array_filter([
            ($report['log_bin'] ?? '') !== '1' ? self::BINARY_LOG_OFF : null,
            strtoupper($this->textOf($report['binlog_format'] ?? '')) !== 'ROW' ? self::ROW_FORMAT_NEEDED : null,
            ($report['gtid_mode'] ?? '') !== 'ON' ? 'Turn on GTIDs on the source (gtid_mode=ON) to keep it in sync.' : null,
            ! $this->includesPrivilege($grants, 'REPLICATION SLAVE') ? $this->privilegeNeeded($source, 'the REPLICATION SLAVE privilege') : null,
            ! $this->includesPrivilege($grants, 'REPLICATION CLIENT') ? $this->privilegeNeeded($source, 'the REPLICATION CLIENT privilege') : null,
            ! $this->canSnapshot($grants) ? $this->privilegeNeeded($source, 'RELOAD or BACKUP_ADMIN for a consistent snapshot') : null,
            mb_strlen($source->password) > self::REPLICATION_PASSWORD_MAX_LENGTH ? 'MySQL replication takes passwords of at most '.self::REPLICATION_PASSWORD_MAX_LENGTH.' characters, so give the source user a shorter one.' : null,
        ]));
    }

    /**
     * @param  array<mixed>  $report
     * @return list<string>
     */
    private function problemsFor(array $report, string $grants, MigrationSource $source): array
    {
        $roleTable = $this->roleTableIn($report);
        $missing = NameList::from($report['missing'] ?? null);

        return array_values(array_filter([
            $this->canRead($grants, $roleTable) ? null : $this->privilegeNeeded($source, "SELECT on mysql.{$roleTable}"),
            $missing === [] ? null : 'The source has no database '.NameList::of($missing).", or {$source->username} cannot see it. Grant {$source->username} SELECT on it, or pick other databases.",
        ]));
    }

    /**
     * @param  array<mixed>  $report
     */
    private function roleTableIn(array $report): string
    {
        return ($report['activate_all_roles_on_login'] ?? '') === 'ON' ? 'role_edges' : 'default_roles';
    }

    /**
     * @param  array<mixed>  $report
     * @return list<string>
     */
    private function warningsFor(array $report): array
    {
        $retention = (int) $this->textOf($report['binlog_expire_logs_seconds'] ?? '');

        if ($retention === 0 || $retention >= self::MINIMUM_BINLOG_RETENTION_SECONDS) {
            return [];
        }

        return ['The source keeps binary logs for less than 14 days (binlog_expire_logs_seconds). Keep them at least 14 days so a long copy can still catch up.'];
    }

    private function canSnapshot(string $grants): bool
    {
        return $this->includesPrivilege($grants, 'RELOAD') || $this->includesPrivilege($grants, 'BACKUP_ADMIN');
    }

    private function canRead(string $grants, string $roleTable): bool
    {
        return str_contains($grants, self::ALL_PRIVILEGES) || preg_match(sprintf(self::ROLE_READ_GRANT, $roleTable), $grants) === 1;
    }

    private function includesPrivilege(string $grants, string $privilege): bool
    {
        return str_contains($grants, self::ALL_PRIVILEGES) || str_contains($grants, $privilege);
    }

    /**
     * @return array<mixed>
     */
    private function reportIn(string $output): array
    {
        $line = $this->lineAfter('REPORT ', $output);
        $decoded = is_string($line) ? json_decode($line, true) : null;

        return is_array($decoded) ? $decoded : ['reachable' => false, 'error' => trim($output)];
    }

    /**
     * @return list<string>
     */
    private function occupiedIn(string $output): array
    {
        $line = $this->lineAfter('OCCUPIED ', $output);

        return is_string($line) ? NameList::parse(trim($line), ',') : [];
    }

    private function lineAfter(string $prefix, string $output): ?string
    {
        $line = collect(explode("\n", $output))->first(fn (string $line): bool => str_starts_with($line, $prefix));

        return is_string($line) ? substr($line, strlen($prefix)) : null;
    }

    private function channelFor(MigrationSource $source, string $file, string $position): string
    {
        return view($this->engine->scriptView('migrations.channel'), [
            'host' => Literal::sql($source->host),
            'port' => $source->port,
            'username' => Literal::sql($source->username),
            'password' => Literal::sql($source->password),
            'file' => Literal::sql($file),
            'position' => $position,
            'tls' => $source->tls,
            'patterns' => $this->patternsOf($source),
        ])->render();
    }

    private function refilterFor(MigrationSource $source): string
    {
        return base64_encode("STOP REPLICA SQL_THREAD FOR CHANNEL 'migration';\n".$this->filterFor($source)."START REPLICA SQL_THREAD FOR CHANNEL 'migration';\n");
    }

    private function filterFor(MigrationSource $source): string
    {
        return view($this->engine->scriptView('migrations.filter'), ['patterns' => $this->patternsOf($source)])->render();
    }

    private function patternsOf(MigrationSource $source): string
    {
        return implode(', ', array_map(Literal::sql(...), $source->tablePatterns()));
    }

    private function textOf(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
