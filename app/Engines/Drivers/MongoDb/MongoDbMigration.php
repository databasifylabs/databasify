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
use App\Engines\Payload;
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
        ]);
        $error = $facts->nullableString('error');

        if ($error !== null) {
            return SourceReport::unreachable($error);
        }

        $listed = collect($facts->objects('listed'))
            ->mapWithKeys(fn (Payload $database): array => [$database->string('name') => $database->int('size')])
            ->except(MongoDbAccounts::RESERVED_DATABASES);
        $names = array_values($listed->keys()->map(fn (int|string $name): string => (string) $name)->all());
        $wanted = $databases === [] ? $names : $databases;
        $readable = array_values(array_intersect($wanted, $names));
        $version = $facts->string('version');
        $line = $this->driver->node->database->version_line;
        $standalone = $facts->string('setName') === '';
        $sameMajor = implode('.', array_slice(explode('.', $version), 0, 2)) === $line;
        $missingRoles = $this->missingRolesOf($facts->strings('roles'));
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

        return SourceReport::checked(
            $version,
            ! $standalone && $sameMajor && $missingRoles === [],
            intdiv($listed->only($readable)->reduce(fn (int $total, int $size): int => $total + $size, 0), 1 << 20),
            $names,
            array_values(array_diff($wanted, $names)),
            occupied: array_values(array_intersect($readable, $facts->strings('occupied'))),
            warnings: $warnings,
        );
    }

    public function start(MigrationStart $start): void
    {
        $databases = $start->databases === [] ? $this->check($start->source, [])->databases : $start->databases;
        $sourceUri = MongoDbFiles::sourceUriOf($start->source);
        $admin = Secrets::of($this->driver->node->database->settings)->admin ?? throw new InvalidEngineRequest('MongoDB needs the admin secret');
        $targetUri = MongoDbFiles::localUriOf($admin);
        $copyScript = $this->driver->script('engines.mongodb.jobs.migration-copy', [
            'continuous' => $start->continuous,
            'databases' => $databases,
            'startRequest' => json_encode(['includeNamespaces' => array_map(fn (string $database): string => "{$database}.*", $databases)], JSON_THROW_ON_ERROR),
        ]);

        $this->driver->run('engines.mongodb.migration.start', ['copyScript' => $copyScript], [
            'SOURCE_URI' => $sourceUri,
            'TARGET_URI' => $targetUri,
            'SOURCE_TOOL_CONFIG' => MongoDbFiles::toolConfigOf($sourceUri),
            'TARGET_TOOL_CONFIG' => MongoDbFiles::toolConfigOf($targetUri),
        ]);
    }

    public function progress(): MigrationProgress
    {
        $reply = $this->driver->read('engines.mongodb.migration.progress');

        if ($reply->string('loadState') === 'not-found') {
            return MigrationProgress::stalled('');
        }

        $clustersync = $reply->nullableObject('clustersync');
        $state = $clustersync?->nullableString('state');
        $log = $reply->string('log');

        return match (true) {
            $state === 'failed' => MigrationProgress::broken('ClusterSync failed: '.($clustersync->nullableString('error') ?: 'no error given')),
            $state === 'running' && ($clustersync->nullableObject('initialSync')?->toArray()['completed'] ?? false) === true => MigrationProgress::caughtUp($log, max($clustersync->nullableInt('lagTimeSeconds') ?? 0, 0)),
            $reply->string('activeState') === 'active' && $reply->string('subState') === 'running' => MigrationProgress::working($log),
            $reply->string('exitStatus') === '0' => MigrationProgress::caughtUp($log),
            default => MigrationProgress::broken($log),
        };
    }

    public function stop(string $key, bool $cutover): Operation
    {
        return $this->driver->task($key, 'engines.mongodb.migration.stop', ['cutover' => $cutover]);
    }

    /**
     * @param  list<string>  $roles
     * @return list<string>
     */
    private function missingRolesOf(array $roles): array
    {
        return in_array('root', $roles, true) ? [] : array_values(array_diff(MongoDbAccounts::SOURCE_ROLES, $roles));
    }
}
