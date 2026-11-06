<?php

declare(strict_types=1);

namespace App\DatabaseProviders\Drivers\Shared;

use App\DatabaseProviders\Contracts\DatabaseDriver;
use App\DatabaseProviders\Groups\EngineMigration;
use App\DTOs\Migrations\MigrationProgress;
use App\DTOs\Migrations\MigrationSource;
use App\DTOs\Migrations\SourceForm;
use App\DTOs\Migrations\SourceReport;
use App\Enums\Databases\SentinelFlavor;
use App\Models\Database;
use App\Models\DatabaseMigration;
use App\Support\Scripts\Literal;
use App\Support\Ssh\KeyValueOutput;

final readonly class SentinelMigration extends EngineMigration
{
    private const string WRITER_ADDRESS = '127.0.0.1:6379';

    public function __construct(DatabaseDriver $engine, Database $database, private SentinelFlavor $flavor)
    {
        parent::__construct($engine, $database);
    }

    public function form(): SourceForm
    {
        return new SourceForm(6379, credentialsRequired: false, selectsDatabases: false);
    }

    public function check(MigrationSource $source): SourceReport
    {
        $values = KeyValueOutput::parse($this->database->primaryOrSeed()->ssh()->exec($this->engine->scriptView('migrations.check'), [
            ...$this->scriptData()->for(),
            ...$this->credentialsOf($source),
        ]));

        if (isset($values['error'])) {
            return SourceReport::unreachable($values['error'] !== '' ? $values['error'] : self::NO_ANSWER);
        }

        return new SourceReport(true, facts: [
            'version' => $values['valkey_version'] ?? $values['redis_version'] ?? '',
            'keys' => $values['keys'] ?? '0',
            'used_memory' => $values['used_memory_human'] ?? '',
            'role' => $values['role'] ?? '',
        ], warnings: $this->moduleWarningsFor($values));
    }

    public function start(DatabaseMigration $migration): void
    {
        $node = $migration->host();

        $node->ssh()->exec($this->engine->scriptView('migrations.start'), [
            ...$this->scriptData()->for(),
            'continuous' => $this->isContinuous($migration),
            'address' => $migration->source->address(),
            'config' => $this->shakeConfigFor($migration),
        ], "{$node->server->name}: starting redis-shake for {$migration->source->address()}");
    }

    public function progress(DatabaseMigration $migration): MigrationProgress
    {
        $values = KeyValueOutput::parse($migration->ssh()->exec($this->engine->scriptView('migrations.progress'), [
            ...$this->engine->scriptVariables(),
            'container' => $this->engine->container(),
        ]));
        $log = $values['log'] ?? '';
        $running = ($values['running'] ?? '') === 'true';

        return match (true) {
            ($values['unit'] ?? '') === 'missing' => MigrationProgress::broken('redis-shake is no longer on the server.'),
            $running && ($values['consistent'] ?? '') === 'true' => MigrationProgress::caughtUp('In sync with the source.', 0),
            $running => MigrationProgress::working("Copying from the source: {$log}"),
            $migration->isOneTime() && ($values['exit_code'] ?? '') === '0' => MigrationProgress::caughtUp('Copied every key from the source.'),
            ($values['unreadable'] ?? '') !== '' => MigrationProgress::broken("redis-shake cannot stream the {$values['unreadable']}, so the continuous migration stopped. Run a one-time migration instead: it copies every key with DUMP and RESTORE."),
            default => MigrationProgress::broken("redis-shake stopped (the source may not allow SYNC, try a one-time migration): {$log}"),
        };
    }

    public function follow(DatabaseMigration $migration): void {}

    public function stop(DatabaseMigration $migration): void
    {
        $migration->ssh()->exec($this->engine->scriptView('migrations.stop'), $this->scriptData()->for(), timeout: self::STOP_TIMEOUT);
    }

    private function shakeConfigFor(DatabaseMigration $migration): string
    {
        return view($this->engine->scriptView('migrations.shake-config'), [
            'continuous' => $this->isContinuous($migration),
            'sourceAddress' => Literal::toml($migration->source->address()),
            'sourceUsername' => Literal::toml($migration->source->username),
            'sourcePassword' => Literal::toml($migration->source->password),
            'sourceTls' => $migration->source->tls,
            'writerAddress' => Literal::toml(self::WRITER_ADDRESS),
            'writerPassword' => Literal::toml($migration->database->settings->secret($this->flavor->env('PASSWORD'))),
            'workDirectory' => Literal::toml($this->engine->directory().'/migration/work'),
        ])->render();
    }

    /**
     * @param  array<string, string>  $values
     * @return list<string>
     */
    private function moduleWarningsFor(array $values): array
    {
        $modules = $values['modules'] ?? '';
        $indexes = (int) ($values['search_number_of_indexes'] ?? 0);

        return array_values(array_filter([
            $modules === '' ? null : "The source runs modules ({$modules}). A continuous migration stops at the first key of a module type; a one-time migration copies those keys.",
            $indexes === 0 ? null : "The source has {$indexes} search indexes. Their keys are copied but the indexes are not: create them again with FT.CREATE on this database.",
        ]));
    }
}
