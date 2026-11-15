<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MongoDb;

use App\DTOs\Migrations\MigrationProgress;
use App\DTOs\Migrations\SourceReport;
use App\Engines\Contracts\Migration;
use App\Engines\Data\MigrationSource;
use App\Engines\Data\MigrationStart;
use App\Engines\Data\Operation;
use App\Engines\Data\Secrets;
use App\Exceptions\Engines\InvalidEngineRequest;

final readonly class MongoDbMigration implements Migration
{
    public const string UNIT = 'mongodb-migration';

    public function __construct(
        private MongoDbDriver $driver,
    ) {}

    public function check(MigrationSource $source, array $databases): SourceReport
    {
        $facts = $this->driver->read('engines.mongodb.migration.check', timeout: 600, env: [
            'SOURCE_HOST' => "{$source->host}:{$source->port}",
            'SOURCE_USER' => $source->username,
            'SOURCE_PASSWORD' => $source->password,
            'SOURCE_TLS' => $source->tls ? 'true' : 'false',
            'DATABASES' => json_encode($databases, JSON_THROW_ON_ERROR),
            'RESERVED_DATABASES' => json_encode(MongoDbAccounts::RESERVED_DATABASES, JSON_THROW_ON_ERROR),
        ]);
        $error = $facts->nullableString('error');

        if ($error !== null) {
            return SourceReport::unreachable($error);
        }

        $version = $facts->string('version');
        $line = $this->driver->node->database->version_line;
        $standalone = $facts->string('setName') === '';
        $sameMajor = implode('.', array_slice(explode('.', $version), 0, 2)) === $line;
        $missingRoles = $facts->strings('missingRoles');
        $warnings = [];

        if ($standalone) {
            $warnings[] = 'the source is a standalone server without an oplog; only a one-shot copy is possible';
        }

        if (! $sameMajor) {
            $warnings[] = "continuous sync needs the same major version ({$line}); only a one-shot copy is possible";
        }

        if ($missingRoles !== []) {
            $warnings[] = 'continuous sync needs the backup, clusterMonitor and readAnyDatabase roles on the source: grant '.implode(', ', $missingRoles).' to the source user; only a one-shot copy is possible';
        }

        return SourceReport::checked($version, ! $standalone && $sameMajor && $missingRoles === [], intdiv($facts->int('size'), 1 << 20), $facts->strings('databases'), $facts->strings('unreadable'), occupied: $facts->strings('occupied'), warnings: $warnings);
    }

    public function start(MigrationStart $start): void
    {
        $databases = $start->databases === [] ? $this->check($start->source, [])->databases : $start->databases;
        $sourceUri = MongoDbFiles::sourceUriOf($start->source);
        $admin = Secrets::of($this->driver->node->database->settings)->admin ?? throw new InvalidEngineRequest('MongoDB needs the admin secret');
        $targetUri = MongoDbFiles::localUriOf($admin);

        $this->driver->run('engines.mongodb.migration.start', env: [
            'SOURCE_URI' => $sourceUri,
            'TARGET_URI' => $targetUri,
            'SOURCE_TOOL_CONFIG' => MongoDbFiles::toolConfigOf($sourceUri),
            'TARGET_TOOL_CONFIG' => MongoDbFiles::toolConfigOf($targetUri),
            'CLUSTERSYNC_START_REQUEST' => json_encode(['includeNamespaces' => array_map(fn (string $database): string => "{$database}.*", $databases)], JSON_THROW_ON_ERROR),
            'MIGRATION_DATABASES' => implode("\n", $databases),
            'MIGRATION_CONTINUOUS' => $start->continuous ? 'true' : 'false',
            'COPY_SCRIPT' => base64_encode($this->driver->script('engines.mongodb.jobs.migration-copy')),
        ]);
    }

    public function progress(): MigrationProgress
    {
        return MigrationProgress::fromPayload(($this->driver->read('engines.mongodb.migration.progress')));
    }

    public function stop(string $key, bool $cutover): Operation
    {
        return $this->driver->task($key, 'engines.mongodb.migration.stop', env: ['MIGRATION_CUTOVER' => $cutover ? 'true' : 'false']);
    }
}
