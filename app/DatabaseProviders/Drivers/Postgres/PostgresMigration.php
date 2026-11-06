<?php

declare(strict_types=1);

namespace App\DatabaseProviders\Drivers\Postgres;

use App\DatabaseProviders\Groups\EngineMigration;
use App\DTOs\Migrations\MigrationProgress;
use App\DTOs\Migrations\MigrationSource;
use App\DTOs\Migrations\SourceForm;
use App\DTOs\Migrations\SourceReport;
use App\Models\DatabaseMigration;
use App\Support\Databases\SchemaName;
use App\Support\Migrations\NameList;
use App\Support\Ssh\KeyValueOutput;

final readonly class PostgresMigration extends EngineMigration
{
    private const string STATE_DIR = '/srv/postgres/migration';

    private const int MINIMUM_LOGICAL_VERSION = 10;

    private const string PUBLICATION_PREFIX = 'databasify_migration_';

    private const int TARGET_TAG_LENGTH = 12;

    public function form(): SourceForm
    {
        return new SourceForm(5432, credentialsRequired: true, selectsDatabases: true, reservedDatabases: ['postgres', 'template0', 'template1']);
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
            'wal_level' => $values['wal_level'] ?? '',
            'databases' => $values['databases'] ?? '',
        ], occupied: $this->occupiedIn($values), problems: $this->problemsFor($values), databases: NameList::parse($values['databases'] ?? ''));
    }

    public function start(DatabaseMigration $migration): void
    {
        $node = $migration->host();
        $source = $migration->source;

        $node->ssh()->exec($this->engine->scriptView('migrations.copy'), [
            ...$this->sourceData($source),
            'continuous' => $this->isContinuous($migration),
            'address' => $source->address(),
            'owner' => $this->database->settings->defaultUsername(),
            'publication' => $this->publicationName(),
        ], "{$node->server->name}: starting the copy from {$source->address()}");
    }

    public function progress(DatabaseMigration $migration): MigrationProgress
    {
        $values = KeyValueOutput::parse($migration->ssh()->exec($this->engine->scriptView('migrations.progress'), $this->workspaceIn(self::STATE_DIR)));
        $log = $values['log'] ?? '';

        return match (true) {
            ($values['copy'] ?? '') === 'running' => MigrationProgress::working("Copying: {$log}"),
            ($values['copy'] ?? '') === 'lost' => MigrationProgress::broken("The copy stopped unexpectedly: {$log}"),
            isset($values['copy_exit']) && $values['copy_exit'] !== '0' => MigrationProgress::broken("The copy failed: {$log}"),
            $this->isContinuous($migration) => $this->subscriptionProgressFrom($values),
            default => MigrationProgress::caughtUp('Copied every database from the source.'),
        };
    }

    public function follow(DatabaseMigration $migration): void {}

    public function stop(DatabaseMigration $migration): void
    {
        $migration->ssh()->exec($this->engine->scriptView('migrations.stop'), $this->workspaceIn(self::STATE_DIR), timeout: self::STOP_TIMEOUT);
    }

    private function publicationName(): string
    {
        return self::PUBLICATION_PREFIX.substr(str_replace('-', '', $this->database->id), -self::TARGET_TAG_LENGTH);
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceData(MigrationSource $source): array
    {
        return [...$this->workspaceIn(self::STATE_DIR), ...$this->credentialsOf($source), 'databases' => implode(' ', $source->databases), 'schemaName' => SchemaName::CHARSET];
    }

    /**
     * @param  array<string, string>  $values
     * @return list<string>
     */
    private function occupiedIn(array $values): array
    {
        return NameList::parse($values['occupied'] ?? '');
    }

    /**
     * @param  array<string, string>  $values
     * @return list<string>
     */
    private function problemsFor(array $values): array
    {
        $unreadable = NameList::parse($values['unreadable'] ?? '');

        if ($unreadable === []) {
            return [];
        }

        return ['The source user cannot read these tables, so they cannot be copied: '.NameList::of($unreadable).'. Grant it SELECT on them and USAGE on their schemas, or migrate as their owner.'];
    }

    /**
     * @param  array<string, string>  $values
     * @return list<string>
     */
    private function blockersFor(array $values, MigrationSource $source): array
    {
        $major = (int) explode('.', $values['version'] ?? '0')[0];
        $unowned = NameList::parse($values['unowned'] ?? '');

        return array_values(array_filter([
            ($values['wal_level'] ?? '') !== 'logical' ? 'Set wal_level to logical on the source and restart it.' : null,
            ($values['can_replicate'] ?? '') !== 't' ? $this->privilegeNeeded($source, 'the REPLICATION attribute') : null,
            $major < self::MINIMUM_LOGICAL_VERSION ? 'Continuous sync needs PostgreSQL 10 or newer on the source.' : null,
            $unowned !== [] ? 'Continuous sync publishes every table, so the source user must own them or be a superuser: '.NameList::of($unowned).'.' : null,
        ]));
    }

    /**
     * @param  array<string, string>  $values
     */
    private function subscriptionProgressFrom(array $values): MigrationProgress
    {
        $tables = (int) ($values['subscribed_tables'] ?? 0);
        $ready = (int) ($values['ready_tables'] ?? 0);

        if ($tables === 0) {
            return MigrationProgress::caughtUp('Copied every database, none of them has tables to follow.', 0);
        }

        if ($ready < $tables) {
            return MigrationProgress::working("Copying tables: {$ready} of {$tables} ready.");
        }

        $lag = $this->slowestLagIn($values);

        if ($lag === null) {
            return MigrationProgress::stalled('The subscription is reconnecting to the source.');
        }

        return MigrationProgress::caughtUp("Replicating, {$lag}s behind the source.", $lag);
    }

    /**
     * @param  array<string, string>  $values
     */
    private function slowestLagIn(array $values): ?int
    {
        $lags = NameList::parse($values['lags'] ?? '');

        if ($lags === [] || ! array_all($lags, fn (string $lag): bool => ctype_digit($lag))) {
            return null;
        }

        return max(array_map(intval(...), $lags));
    }
}
