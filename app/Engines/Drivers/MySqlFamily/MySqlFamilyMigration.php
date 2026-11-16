<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MySqlFamily;

use App\DTOs\Migrations\MigrationProgress;
use App\DTOs\Migrations\SourceReport;
use App\Engines\Contracts\Migration;
use App\Engines\Data\MigrationSource;
use App\Engines\Data\MigrationStart;
use App\Engines\Data\Operation;
use App\Engines\Payload;
use App\Exceptions\Engines\InvalidEngineRequest;
use App\Models\DatabaseNode;

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
        $facts = $this->scripts->read($this->driver, 'engines.mysql-family.migration.check', env: $this->sourceEnvOf('SOURCE', $source), timeout: 600);
        $error = $facts->nullableString('error');

        if ($error !== null) {
            return SourceReport::unreachable(strtok($error, "\n") ?: $error);
        }

        $visible = array_values(collect($facts->strings('visible'))->diff($engine->reservedDatabases)->sort()->all());
        $wanted = $databases === [] ? $visible : $databases;
        $sizes = $facts->object('sizes');
        $local = $facts->object('localTables');
        $missing = $facts->object('missingKeys');
        $countIn = fn (Payload $counts, string $database): int => $counts->has($database) ? $counts->int($database) : 0;
        $readable = collect($wanted)->intersect($visible);
        $unreadable = array_values(array_diff($wanted, $visible));
        $bytes = $readable->sum(fn (string $database): int => $countIn($sizes, $database));
        $occupied = array_values($readable->filter(fn (string $database): bool => $countIn($local, $database) > 0)->all());
        $warnings = $readable
            ->filter(fn (string $database): bool => $countIn($missing, $database) > 0)
            ->map(fn (string $database): string => "{$database} has {$countIn($missing, $database)} tables without a primary key, which a cluster refuses")
            ->all();

        $version = $facts->string('version');
        $release = explode('-', $version, 2)[0];
        [$canReplicate, $readiness] = $engine->id === 'mysql' ? $this->mysqlReadiness($facts, $release) : $this->mariadbReadiness($facts, $version, $release);
        $problems = $engine->id === 'mysql' && ! $facts->bool('roleEdgesReadable') ? [self::ROLES_PROBLEM] : [];

        return SourceReport::checked($release, $canReplicate, intdiv($bytes, 1 << 20), $visible, $unreadable, occupied: $occupied, warnings: [...$warnings, ...$readiness], problems: $problems);
    }

    public function start(MigrationStart $start): void
    {
        $databases = $start->databases === [] ? $this->check($start->source, [])->databases : $start->databases;

        if ($databases === []) {
            throw new InvalidEngineRequest('the source has no databases to copy');
        }

        $this->scripts->run($this->driver, 'engines.mysql-family.migration.start', [
            'continuous' => $start->continuous,
            'copyScript' => $this->driver->script('engines.mysql-family.jobs.migration-copy', ['engine' => $this->driver->engine()]),
        ], [
            ...$this->sourceEnvOf('MIGRATION', $start->source),
            'MIGRATION_DATABASES' => implode("\n", $databases),
            'MIGRATION_CONTINUOUS' => $start->continuous ? 'true' : 'false',
            'MIGRATION_OWNER' => $start->owner ?? '',
            'MYSQLSH_URI' => rawurlencode($start->source->username)."@{$start->source->host}:{$start->source->port}",
        ]);
    }

    public function progress(): MigrationProgress
    {
        return MigrationProgress::fromPayload($this->scripts->read($this->driver, 'engines.mysql-family.migration.progress'));
    }

    public function stop(string $key, bool $cutover): Operation
    {
        $operation = $this->scripts->start($this->driver, $key, 'engines.mysql-family.migration.stop');
        $this->driver->node->database->membersOtherThan($this->driver->node)
            ->each(fn (DatabaseNode $member) => $member->driver()->run('engines.mysql-family.migration.stop', timeout: 600));

        return $operation;
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

        return [$gtidMode === 'ON' && $this->grants($facts, 'REPLICATION SLAVE') && $this->grants($facts, 'REPLICATION CLIENT'), $warnings];
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

        return [$logBin === '1' && $this->grants($facts, 'REPLICATION SLAVE'), $warnings];
    }

    private function grants(Payload $facts, string $privilege): bool
    {
        $granted = strtoupper($facts->string('grants'));

        return str_contains($granted, $privilege) || str_contains($granted, 'ALL PRIVILEGES ON *.*');
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
