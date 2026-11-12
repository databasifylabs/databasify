<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MySqlFamily;

use App\Engines\Data\MigrationSource;
use App\Engines\Payload;
use App\Engines\Requests\MigrationCheck;
use App\Engines\Requests\MigrationStart;
use App\Engines\Responses\Done;
use App\Engines\Responses\MigrationCheck as MigrationCheckReply;
use App\Engines\Responses\MigrationProgress;
use App\Models\DatabaseNode;

final readonly class Migration
{
    private const string ROLES_PROBLEM = 'MySQL Shell copies only as a source user with SELECT on mysql.* (it reads the roles), grant it and check again';

    public function __construct(private FamilyScripts $scripts) {}

    public function check(DatabaseNode $node, MigrationCheck $request): MigrationCheckReply
    {
        $engine = FamilyScripts::engineOf($node);
        $facts = $this->scripts->run($node, 'mysql-family/migration/check', [
            ...$this->sourceEnvOf('SOURCE', $request->source),
            'DATABASES' => json_encode($request->databases, JSON_THROW_ON_ERROR),
            'RESERVED_DATABASES' => json_encode($engine->reservedDatabases, JSON_THROW_ON_ERROR),
        ], timeout: 600)->json();
        $error = $facts->nullableString('error');

        if ($error !== null) {
            return new MigrationCheckReply(false, strtok($error, "\n") ?: $error, null, null, false, null, [], [], [], [], [], []);
        }

        $visible = $facts->strings('visible');
        $wanted = $request->databases === [] ? $visible : $request->databases;
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

        return new MigrationCheckReply(true, null, $release, null, $canReplicate, intdiv($bytes, 1 << 20), $visible, $unreadable, [], $occupied, [...$warnings, ...$readiness], $problems);
    }

    public function start(DatabaseNode $node, MigrationStart $request): Done
    {
        $this->scripts->run($node, 'mysql-family/migration/start', [
            ...$this->sourceEnvOf('MIGRATION', $request->source),
            'MIGRATION_DATABASES' => implode(' ', $request->databases),
            'MIGRATION_CONTINUOUS' => $request->continuous ? 'true' : 'false',
            'MIGRATION_UNIT' => FamilyScripts::engineOf($node)->migrationUnit(),
        ]);

        return new Done;
    }

    public function progress(DatabaseNode $node): MigrationProgress
    {
        return MigrationProgress::fromPayload($this->scripts->run($node, 'mysql-family/migration/progress', ['MIGRATION_UNIT' => FamilyScripts::engineOf($node)->migrationUnit()])->json());
    }

    public function stop(DatabaseNode $node): Done
    {
        $this->scripts->run($node, 'mysql-family/migration/stop', ['MIGRATION_UNIT' => FamilyScripts::engineOf($node)->migrationUnit()], timeout: 600);

        return new Done;
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
