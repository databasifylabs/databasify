<?php

declare(strict_types=1);

namespace App\DatabaseProviders\Drivers\Mariadb;

use App\DatabaseProviders\Drivers\Shared\BinlogMigration;
use App\DTOs\Migrations\MigrationProgress;
use App\DTOs\Migrations\MigrationSource;
use App\DTOs\Migrations\SourceForm;
use App\DTOs\Migrations\SourceReport;
use App\Models\DatabaseMigration;
use App\Services\Ssh\Ssh;
use App\Support\Databases\SchemaName;
use App\Support\Migrations\NameList;
use App\Support\Scripts\Literal;
use App\Support\Ssh\KeyValueOutput;

final readonly class MariadbMigration extends BinlogMigration
{
    private const string STATE_DIR = '/srv/mariadb/migration';

    public function form(): SourceForm
    {
        return new SourceForm(3306, credentialsRequired: true, selectsDatabases: true, reservedDatabases: ['mysql', 'sys', 'performance_schema', 'information_schema']);
    }

    public function check(MigrationSource $source): SourceReport
    {
        $values = KeyValueOutput::parse($this->database->primaryServer()->ssh($this->database)->exec($this->engine->scriptView('migrations.check'), $this->sourceData($source)));

        if (($values['reachable'] ?? '') !== 'yes') {
            return SourceReport::unreachable($values['error'] ?? self::NO_ANSWER);
        }

        return new SourceReport(true, $this->blockersFor($values, $source), [
            'version' => $values['version'] ?? '',
            'size_mb' => $values['size_mb'] ?? '0',
            'binlog_format' => $values['binlog_format'] ?? '',
        ], problems: $this->problemsFor($values, $source), databases: NameList::parse($values['databases'] ?? ''));
    }

    public function start(DatabaseMigration $migration): void
    {
        $node = $migration->host();
        $source = $migration->source;

        $node->ssh()->exec($this->engine->scriptView('migrations.copy'), [
            ...$this->sourceData($source),
            'databases' => implode(' ', $source->databases),
            'schemaName' => SchemaName::CHARSET,
            'address' => $source->address(),
        ], "{$node->server->name}: starting the copy from {$source->address()}");
    }

    public function progress(DatabaseMigration $migration): MigrationProgress
    {
        $values = KeyValueOutput::parse($migration->ssh()->exec($this->engine->scriptView('migrations.progress'), [
            ...$this->workspaceIn(self::STATE_DIR),
            'refilter' => $this->refilterFor($migration->source),
        ]));
        $log = $values['log'] ?? '';

        return match (true) {
            ($values['copy'] ?? '') === 'running' => MigrationProgress::working("Copying: {$log}"),
            ($values['copy'] ?? '') === 'lost' => MigrationProgress::broken("The copy stopped unexpectedly: {$log}"),
            isset($values['copy_exit']) => $this->copyResultFrom($migration, $values),
            default => $this->replicationFrom($values),
        };
    }

    public function follow(DatabaseMigration $migration): void
    {
        [$file, $position] = array_pad(explode(' ', $migration->position->binlog ?? ''), 2, '');

        $this->ensureReplicableFrom($file, $position);

        $migration->ssh()->exec($this->engine->scriptView('migrations.replicate'), [
            ...$this->scriptData()->for(),
            'stateDir' => self::STATE_DIR,
            'sql' => $this->channelFor($migration->source, $file, $position),
        ], timeout: Ssh::TIMEOUT);
    }

    public function stop(DatabaseMigration $migration): void
    {
        $migration->ssh()->exec($this->engine->scriptView('migrations.stop'), $this->workspaceIn(self::STATE_DIR), timeout: self::STOP_TIMEOUT);
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceData(MigrationSource $source): array
    {
        return [...$this->workspaceIn(self::STATE_DIR), ...$this->credentialsOf($source)];
    }

    /**
     * @param  array<string, string>  $values
     * @return list<string>
     */
    private function blockersFor(array $values, MigrationSource $source): array
    {
        $grants = $values['grants'] ?? '';

        return array_values(array_filter([
            ($values['log_bin'] ?? '') !== '1' ? self::BINARY_LOG_OFF : null,
            strtoupper($values['binlog_format'] ?? '') !== 'ROW' ? self::ROW_FORMAT_NEEDED : null,
            ! $this->includesAnyOf($grants, 'REPLICATION SLAVE', 'REPLICATION REPLICA') ? $this->privilegeNeeded($source, 'the REPLICATION SLAVE privilege') : null,
            ! $this->includesAnyOf($grants, 'BINLOG MONITOR', 'REPLICATION CLIENT') ? $this->privilegeNeeded($source, 'the BINLOG MONITOR privilege (REPLICATION CLIENT before MariaDB 10.5)') : null,
        ]));
    }

    /**
     * @param  array<string, string>  $values
     * @return list<string>
     */
    private function problemsFor(array $values, MigrationSource $source): array
    {
        return $this->includesAnyOf($values['grants'] ?? '', 'RELOAD') ? [] : [$this->privilegeNeeded($source, 'the RELOAD privilege')];
    }

    private function includesAnyOf(string $grants, string ...$privileges): bool
    {
        return collect([self::ALL_PRIVILEGES, ...$privileges])->contains(fn (string $privilege): bool => str_contains($grants, $privilege));
    }

    /**
     * @param  array<string, string>  $values
     */
    private function copyResultFrom(DatabaseMigration $migration, array $values): MigrationProgress
    {
        if ($values['copy_exit'] !== '0') {
            return MigrationProgress::broken('The copy failed: '.($values['log'] ?? ''));
        }

        $migration->update(['position' => $migration->position->withBinlog($values['position'] ?? '')]);

        return MigrationProgress::caughtUp('Copied every database from the source.');
    }

    /**
     * @param  array<string, string>  $values
     */
    private function replicationFrom(array $values): MigrationProgress
    {
        if (! isset($values['Slave_IO_Running'])) {
            return MigrationProgress::broken('Neither the copy nor the replication connection is running.');
        }

        $running = $values['Slave_IO_Running'] === 'Yes' && ($values['Slave_SQL_Running'] ?? '') === 'Yes';

        return $this->replicationProgressOf($values, $running, $values['Seconds_Behind_Master'] ?? '', 'The migration connection is reconnecting to the source.');
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
        return base64_encode("STOP SLAVE 'migration';\n".$this->filterFor($source)."START SLAVE 'migration';\n");
    }

    private function filterFor(MigrationSource $source): string
    {
        return view($this->engine->scriptView('migrations.filter'), ['patterns' => $this->patternsOf($source)])->render();
    }

    private function patternsOf(MigrationSource $source): string
    {
        return $source->databases === [] ? '' : Literal::sql(implode(',', $source->tablePatterns()));
    }
}
