<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Postgres;

use App\DTOs\Migrations\MigrationProgress;
use App\DTOs\Migrations\SourceReport;
use App\Engines\Contracts\Migration;
use App\Engines\Data\MigrationSource;
use App\Engines\Data\MigrationStart;
use App\Engines\Scripts\ScriptView;
use App\Exceptions\Engines\InvalidEngineRequest;

final readonly class PostgresMigration implements Migration
{
    private const int CHECK_TIMEOUT = 600;

    private const int STOP_TIMEOUT = 600;

    public function __construct(
        private PostgresDriver $driver,
        private ScriptView $views,
    ) {}

    public function check(MigrationSource $source, array $databases): SourceReport
    {
        $env = [...$this->sourceEnv($source), 'DATABASES' => json_encode($databases, JSON_THROW_ON_ERROR)];

        return SourceReport::fromPayload(($this->driver->read('postgres/migration/check', $this->driver->data(), timeout: self::CHECK_TIMEOUT, env: $env)));
    }

    public function start(MigrationStart $start): void
    {
        if ($start->owner === null) {
            throw new InvalidEngineRequest('invalid_request', 'PostgreSQL migrations need an owner');
        }

        if ($start->continuous) {
            throw new InvalidEngineRequest('invalid_request', 'continuous PostgreSQL migrations need a publication');
        }

        $this->driver->run('postgres/migration/start', $this->driver->data(), env: [
            ...$this->sourceEnv($start->source),
            'MIGRATION_DATABASES' => implode(' ', $start->databases),
            'MIGRATION_OWNER' => $start->owner,
            'MIGRATION_CONTINUOUS' => 'false',
            'MIGRATION_PUBLICATION' => '',
            'COPY_SCRIPT' => base64_encode($this->views->render($this->driver->node, 'postgres/migration/copy', $this->driver->data())),
        ]);
    }

    public function progress(): MigrationProgress
    {
        return MigrationProgress::fromPayload(($this->driver->read('postgres/migration/progress', $this->driver->data())));
    }

    public function stop(): void
    {
        $this->driver->run('postgres/migration/stop', $this->driver->data(), timeout: self::STOP_TIMEOUT);
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
