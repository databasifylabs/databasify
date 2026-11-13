<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MongoDb;

use App\DTOs\Migrations\MigrationProgress;
use App\DTOs\Migrations\SourceReport;
use App\Engines\Contracts\Migration;
use App\Engines\Data\MigrationSource;
use App\Engines\Data\MigrationStart;
use App\Engines\Responses\MigrationCheck as MigrationCheckReply;
use App\Engines\Responses\MigrationProgress as EngineMigrationProgress;

final readonly class MongoDbMigration implements Migration
{
    public const string UNIT = 'mongodb-migration';

    public function __construct(
        private MongoDbDriver $driver,
    ) {}

    public function check(MigrationSource $source, array $databases): SourceReport
    {
        $facts = $this->driver->read('mongodb/migration/check', timeout: 600, env: [
            'SOURCE_HOST' => "{$source->host}:{$source->port}",
            'SOURCE_USER' => $source->username,
            'SOURCE_PASSWORD' => $source->password,
            'SOURCE_TLS' => $source->tls ? 'true' : 'false',
            'DATABASES' => json_encode($databases, JSON_THROW_ON_ERROR),
            'RESERVED_DATABASES' => json_encode(MongoDbAccounts::RESERVED_DATABASES, JSON_THROW_ON_ERROR),
        ]);
        $error = $facts->nullableString('error');

        if ($error !== null) {
            return SourceReport::fromEngine(new MigrationCheckReply(false, $error, null, null, false, null, [], [], [], [], [], []));
        }

        $version = $facts->string('version');
        $line = $this->driver->node->database->version_line;
        $standalone = $facts->string('setName') === '';
        $sameMajor = implode('.', array_slice(explode('.', $version), 0, 2)) === $line;
        $warnings = [];

        if ($standalone) {
            $warnings[] = 'the source is a standalone server without an oplog; only a one-shot copy is possible';
        }

        if (! $sameMajor) {
            $warnings[] = "continuous sync needs the same major version ({$line}); only a one-shot copy is possible";
        }

        return SourceReport::fromEngine(new MigrationCheckReply(true, null, $version, null, ! $standalone && $sameMajor, intdiv($facts->int('size'), 1 << 20), $facts->strings('databases'), $facts->strings('unreadable'), [], $facts->strings('occupied'), $warnings, []));
    }

    public function start(MigrationStart $start): void
    {
        $this->driver->run('mongodb/migration/start', env: [
            'MIGRATION_HOST' => $start->source->host,
            'MIGRATION_PORT' => (string) $start->source->port,
            'MIGRATION_USER' => $start->source->username,
            'MIGRATION_PASSWORD' => $start->source->password,
            'MIGRATION_TLS' => $start->source->tls ? 'true' : 'false',
            'MIGRATION_DATABASES' => implode(' ', $start->databases),
            'MIGRATION_CONTINUOUS' => $start->continuous ? 'true' : 'false',
        ]);
    }

    public function progress(): MigrationProgress
    {
        return MigrationProgress::fromEngine(EngineMigrationProgress::fromPayload($this->driver->read('mongodb/migration/progress')));
    }

    public function stop(): void
    {
        $this->driver->run('mongodb/migration/stop', timeout: 3900);
    }
}
