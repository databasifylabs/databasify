<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Postgres;

use App\DTOs\Migrations\MigrationProgress;
use App\DTOs\Migrations\SourceReport;
use App\Engines\Contracts\Migration;
use App\Engines\Data\MigrationSource;
use App\Engines\Data\MigrationStart;
use App\Engines\Data\Operation;
use App\Exceptions\Engines\InvalidEngineRequest;

final readonly class PostgresMigration implements Migration
{
    private const int CHECK_TIMEOUT = 600;

    private const string PUBLICATION_PREFIX = 'databasify_migration_';

    private const int TARGET_TAG_LENGTH = 12;

    public function __construct(private PostgresDriver $driver) {}

    public function check(MigrationSource $source, array $databases): SourceReport
    {
        $facts = $this->driver->read('engines.postgres.migration.check', ['databases' => $databases], $this->sourceEnv($source), timeout: self::CHECK_TIMEOUT);

        if ($facts->has('error')) {
            return SourceReport::unreachable($facts->string('error'));
        }

        $walLevel = $facts->string('walLevel');

        return SourceReport::checked(
            version: $facts->string('version'),
            canReplicate: $facts->bool('replicates') && $walLevel === 'logical',
            sizeMb: $facts->int('sizeMb'),
            databases: $facts->strings('databases'),
            unreadable: $facts->strings('unreadable'),
            unowned: $facts->strings('unowned'),
            occupied: $facts->strings('occupied'),
            walLevel: $walLevel,
        );
    }

    public function start(MigrationStart $start): void
    {
        if ($start->owner === null) {
            throw new InvalidEngineRequest('PostgreSQL migrations need an owner');
        }

        $copyScript = $this->driver->script('engines.postgres.migration.copy', [
            'databases' => $start->databases,
            'owner' => $start->owner,
            'continuous' => $start->continuous,
            'publication' => $this->publicationName(),
        ]);

        $this->driver->run('engines.postgres.migration.start', ['copyScript' => $copyScript], $this->sourceEnv($start->source));
    }

    public function progress(): MigrationProgress
    {
        return MigrationProgress::fromPayload($this->driver->read('engines.postgres.migration.progress'));
    }

    public function stop(string $key, bool $cutover): Operation
    {
        return $this->driver->task($key, 'engines.postgres.migration.stop');
    }

    private function publicationName(): string
    {
        return self::PUBLICATION_PREFIX.substr(str_replace('-', '', $this->driver->node->database->id), -self::TARGET_TAG_LENGTH);
    }

    /**
     * @return array{SOURCE_HOST: string, SOURCE_PORT: string, SOURCE_USER: string, SOURCE_PASSWORD: string, SOURCE_SSLMODE: string}
     */
    private function sourceEnv(MigrationSource $source): array
    {
        return [
            'SOURCE_HOST' => $source->host,
            'SOURCE_PORT' => (string) $source->port,
            'SOURCE_USER' => $source->username,
            'SOURCE_PASSWORD' => $source->password,
            'SOURCE_SSLMODE' => $source->tls ? 'require' : 'prefer',
        ];
    }
}
