<?php

declare(strict_types=1);

namespace App\DatabaseProviders\Drivers\Mongodb;

use App\DatabaseProviders\Groups\EngineMigration;
use App\DTOs\Migrations\MigrationProgress;
use App\DTOs\Migrations\MigrationSource;
use App\DTOs\Migrations\SourceForm;
use App\DTOs\Migrations\SourceReport;
use App\Models\DatabaseMigration;
use App\Support\Migrations\NameList;
use App\Support\Ssh\KeyValueOutput;

final readonly class MongodbMigration extends EngineMigration
{
    public const string UNIT = 'mongodb-migration';

    public const string STATE_DIR = Mongodb::ROOT.'/migration';

    public const string CHECKPOINT_DATABASE = 'databasify_migration';

    private const array SYSTEM_DATABASES = ['admin', 'local', 'config'];

    public function form(): SourceForm
    {
        return new SourceForm(27017, credentialsRequired: true, selectsDatabases: true, reservedDatabases: self::SYSTEM_DATABASES);
    }

    public function check(MigrationSource $source): SourceReport
    {
        $values = KeyValueOutput::parse($this->database->primaryServer()->ssh($this->database)->exec($this->engine->scriptView('migrations.check'), [
            ...$this->scriptData()->for(),
            'sourceUri' => $this->uriOf($source),
            'databases' => array_values(array_diff($source->databases, self::SYSTEM_DATABASES)),
        ]));

        if (($values['reachable'] ?? '') !== 'yes') {
            return SourceReport::unreachable($values['error'] ?? self::NO_ANSWER);
        }

        return new SourceReport(true, $this->blockersFor($values, $source), [
            'version' => $values['version'] ?? '',
            'size_mb' => $values['size_mb'] ?? '0',
            'replica_set' => $values['replica_set'] ?? '',
            'databases' => $values['databases'] ?? '',
        ], occupied: $this->occupiedIn($values), databases: NameList::parse($values['databases'] ?? '', ','));
    }

    public function start(DatabaseMigration $migration): void
    {
        $node = $migration->host();

        $node->ssh()->exec($this->engine->scriptView('migrations.start'), [
            ...$this->scriptData()->for(),
            'continuous' => $this->isContinuous($migration),
            'address' => $migration->source->address(),
            'config' => $this->shakeConfigFor($migration),
        ], "{$node->server->name}: starting MongoShake for {$migration->source->address()}");
    }

    public function progress(DatabaseMigration $migration): MigrationProgress
    {
        $values = KeyValueOutput::parse($migration->ssh()->exec($this->engine->scriptView('migrations.progress'), [
            ...$this->engine->scriptVariables(),
            'container' => $this->engine->container(),
        ]));
        $log = $values['log'] ?? '';
        $lag = isset($values['lag']) && ctype_digit($values['lag']) ? (int) $values['lag'] : null;
        $running = ($values['running'] ?? '') === 'true';

        return match (true) {
            ($values['unit'] ?? '') === 'missing' => MigrationProgress::broken('MongoShake is no longer on the server.'),
            ! $running && $migration->isOneTime() && ($values['exit_code'] ?? '') === '0' => MigrationProgress::caughtUp('Copied every collection from the source.'),
            ! $running => MigrationProgress::broken("MongoShake stopped: {$log}"),
            ($values['phase'] ?? '') === 'incremental' && $lag !== null => MigrationProgress::caughtUp('Following changes on the source.', $lag),
            default => MigrationProgress::working('Copying from the source: '.($values['copied'] ?? $log)),
        };
    }

    public function follow(DatabaseMigration $migration): void {}

    public function stop(DatabaseMigration $migration): void
    {
        $migration->ssh()->exec($this->engine->scriptView('migrations.stop'), $this->scriptData()->for(), timeout: self::STOP_TIMEOUT);
    }

    /**
     * @param  array<string, string>  $values
     * @return list<string>
     */
    private function blockersFor(array $values, MigrationSource $source): array
    {
        return match (true) {
            ($values['replica_set'] ?? '') === '' => ['Continuous sync reads the oplog, so the source must be a replica set. Copy it once instead, or turn the source into a one-member replica set first.'],
            ($values['oplog'] ?? '') === 'no' => [$this->privilegeNeeded($source, 'read on the local database (the oplog)')],
            default => [],
        };
    }

    /**
     * @param  array<string, string>  $values
     * @return list<string>
     */
    private function occupiedIn(array $values): array
    {
        return NameList::parse($values['occupied'] ?? '', ',');
    }

    private function shakeConfigFor(DatabaseMigration $migration): string
    {
        $database = $migration->database;

        return view($this->engine->scriptView('migrations.shake-config'), [
            'continuous' => $this->isContinuous($migration),
            'sourceUri' => $this->shakeUriOf($migration->source->username === '' ? '' : "{$migration->source->username}:{$migration->source->password}@", $migration->source->address()),
            'sourceTls' => $migration->source->tls,
            'targetUri' => $this->shakeUriOf('root:'.$database->settings->secret('MONGODB_ROOT_PASSWORD').'@', $this->engine->clientAddress($migration->host())),
            'caFile' => Mongodb::ROOT.'/tls/ca.crt',
            'databases' => implode(';', array_diff($migration->source->databases, self::SYSTEM_DATABASES)),
            'checkpointDatabase' => self::CHECKPOINT_DATABASE,
            'stateDirectory' => self::STATE_DIR,
        ])->render();
    }

    private function uriOf(MigrationSource $source): string
    {
        $credentials = $source->username === '' ? '' : rawurlencode($source->username).':'.rawurlencode($source->password).'@';
        $tls = $source->tls ? 'true' : 'false';

        return "mongodb://{$credentials}{$source->address()}/?authSource=admin&directConnection=true&tls={$tls}&tlsInsecure={$tls}";
    }

    private function shakeUriOf(string $credentials, string $address): string
    {
        return "mongodb://{$credentials}{$address}/admin";
    }
}
