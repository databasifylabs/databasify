<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MySqlFamily;

use App\DTOs\Migrations\MigrationProgress;
use App\DTOs\Migrations\SourceReport;
use App\Engines\Contracts\Migration;
use App\Engines\Data\MigrationSource;
use App\Engines\Data\MigrationStart;
use App\Engines\Payload;
use App\Engines\Responses\MigrationCheck as MigrationCheckReply;
use App\Engines\Responses\MigrationProgress as EngineMigrationProgress;
use App\Exceptions\Engines\InvalidEngineRequest;

final readonly class MySqlFamilyMigration implements Migration
{
    private const string ROLES_PROBLEM = 'MySQL Shell copies only as a source user with SELECT on mysql.* (it reads the roles), grant it and check again';

    public function __construct(
        private MySqlFamilyDriver $driver,
        private FamilyScripts $scripts,
    ) {}

    public function check(MigrationSource $source, array $databases): SourceReport
    {
        $engine = $this->driver->engine();
        $facts = $this->scripts->read($this->driver, 'mysql-family/migration/check', [
            ...$this->sourceEnvOf('SOURCE', $source),
            'DATABASES' => json_encode($databases, JSON_THROW_ON_ERROR),
            'RESERVED_DATABASES' => json_encode($engine->reservedDatabases, JSON_THROW_ON_ERROR),
        ], timeout: 600);
        $error = $facts->nullableString('error');

        if ($error !== null) {
            return SourceReport::fromEngine(new MigrationCheckReply(false, strtok($error, "\n") ?: $error, null, null, false, null, [], [], [], [], [], []));
        }

        $visible = $facts->strings('visible');
        $wanted = $databases === [] ? $visible : $databases;
        $sizes = $facts->object('sizes');
        $local = $facts->object('localTables');
        $missing = $facts->object('missingKeys');
        $unreadable = [];
        $occupied = [];
        $warnings = [];
        $bytes = 0;

        foreach ($wanted as $database) {
            if (! in_array($database, $visible, true)) {
                $unreadable[] = $database;

                continue;
            }

            $bytes += $sizes->int($database);

            if ($local->int($database) > 0) {
                $occupied[] = $database;
            }

            if ($missing->int($database) > 0) {
                $warnings[] = "{$database} has {$missing->int($database)} tables without a primary key, which a cluster refuses";
            }
        }

        $version = $facts->string('version');
        $release = explode('-', $version, 2)[0];
        [$canReplicate, $readiness] = $engine->id === 'mysql' ? $this->mysqlReadiness($facts, $release) : $this->mariadbReadiness($facts, $version, $release);
        $problems = $engine->id === 'mysql' && ! $facts->bool('roleEdgesReadable') ? [self::ROLES_PROBLEM] : [];

        return SourceReport::fromEngine(new MigrationCheckReply(true, null, $release, null, $canReplicate, intdiv($bytes, 1 << 20), $visible, $unreadable, [], $occupied, [...$warnings, ...$readiness], $problems));
    }

    public function start(MigrationStart $start): void
    {
        $databases = $start->databases === [] ? $this->check($start->source, [])->databases : $start->databases;

        if ($databases === []) {
            throw new InvalidEngineRequest('invalid_request', 'the source has no databases to copy');
        }

        $this->scripts->run($this->driver, 'mysql-family/migration/start', [
            ...$this->sourceEnvOf('MIGRATION', $start->source),
            'MIGRATION_DATABASES' => implode(' ', $databases),
            'MIGRATION_CONTINUOUS' => $start->continuous ? 'true' : 'false',
            'MIGRATION_UNIT' => $this->driver->engine()->migrationUnit(),
            'MYSQLSH_URI' => rawurlencode($start->source->username)."@{$start->source->host}:{$start->source->port}",
            'COPY_SCRIPT' => base64_encode($this->driver->script('mysql-family/jobs/migration-copy', ['engine' => $this->driver->engine()])),
        ]);
    }

    public function progress(): MigrationProgress
    {
        return MigrationProgress::fromEngine(EngineMigrationProgress::fromPayload($this->scripts->read($this->driver, 'mysql-family/migration/progress', ['MIGRATION_UNIT' => $this->driver->engine()->migrationUnit()])));
    }

    public function stop(): void
    {
        $this->scripts->run($this->driver, 'mysql-family/migration/stop', ['MIGRATION_UNIT' => $this->driver->engine()->migrationUnit()], timeout: 600);
    }

    /**
     * @return array{bool, list<string>}
     */
    private function mysqlReadiness(Payload $facts, string $release): array
    {
        $gtidMode = $facts->string('gtidMode');
        $warnings = $gtidMode === 'ON' ? [] : ["the source runs with gtid_mode {$gtidMode}, so only a one-shot copy is possible"];

        if ($this->newerThanLocal($release, $facts)) {
            $warnings[] = "the source runs {$release}, newer than this server; copying to an older version is not supported";
        }

        return [$gtidMode === 'ON' && $this->grantsReplication($facts), $warnings];
    }

    /**
     * @return array{bool, list<string>}
     */
    private function mariadbReadiness(Payload $facts, string $version, string $release): array
    {
        if (! str_contains($version, 'MariaDB')) {
            return [false, ['the source is MySQL, whose GTIDs MariaDB cannot follow, so only a one-shot copy is possible']];
        }

        $logBin = $facts->string('logBin');
        $format = $facts->string('binlogFormat');
        $warnings = [];

        if ($logBin !== '1') {
            $warnings[] = 'the source has no binary log, so only a one-shot copy is possible';
        }

        if ($logBin === '1' && $format !== 'ROW') {
            $warnings[] = "the source logs in {$format} format; ROW is safer for a continuous copy";
        }

        if ($this->newerThanLocal($release, $facts)) {
            $warnings[] = "the source runs {$release}, newer than this server; copying to an older version is not supported";
        }

        return [$logBin === '1' && $this->grantsReplication($facts), $warnings];
    }

    private function grantsReplication(Payload $facts): bool
    {
        $granted = strtoupper(implode(' ', $facts->strings('grants')));

        return str_contains($granted, 'REPLICATION SLAVE') || str_contains($granted, 'ALL PRIVILEGES ON *.*');
    }

    private function newerThanLocal(string $release, Payload $facts): bool
    {
        return version_compare($release, explode('-', $facts->string('localVersion'), 2)[0], '>');
    }

    /**
     * @return array<string, string>
     */
    private function sourceEnvOf(string $prefix, MigrationSource $source): array
    {
        return [
            "{$prefix}_HOST" => $source->host,
            "{$prefix}_PORT" => (string) $source->port,
            "{$prefix}_USER" => $source->username,
            "{$prefix}_PASSWORD" => $source->password,
            "{$prefix}_TLS" => $source->tls ? 'true' : 'false',
        ];
    }
}
