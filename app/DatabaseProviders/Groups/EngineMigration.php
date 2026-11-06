<?php

declare(strict_types=1);

namespace App\DatabaseProviders\Groups;

use App\DTOs\Migrations\MigrationProgress;
use App\DTOs\Migrations\MigrationSource;
use App\DTOs\Migrations\SourceForm;
use App\DTOs\Migrations\SourceReport;
use App\Enums\Migrations\MigrationMode;
use App\Models\Database;
use App\Models\DatabaseMigration;

abstract readonly class EngineMigration extends EngineGroup
{
    public const int STOP_TIMEOUT = 240;

    protected const string NO_ANSWER = 'No answer from the source.';

    abstract public function form(): SourceForm;

    abstract public function check(MigrationSource $source): SourceReport;

    abstract public function start(DatabaseMigration $migration): void;

    abstract public function progress(DatabaseMigration $migration): MigrationProgress;

    abstract public function follow(DatabaseMigration $migration): void;

    abstract public function stop(DatabaseMigration $migration): void;

    /**
     * @return array<string, mixed>
     */
    protected function workspaceIn(string $stateDir): array
    {
        return $this->scriptData()->workspace($stateDir);
    }

    protected function isContinuous(DatabaseMigration $migration): bool
    {
        return $migration->mode === MigrationMode::Continuous;
    }

    protected function privilegeNeeded(MigrationSource $source, string $privilege): string
    {
        $database = $this->inAppDatabaseOf($source);
        $fix = $database === null ? "Grant {$privilege} to {$source->username} on the source." : "Redeploy {$database->name} to grant it.";

        return "The source user needs {$privilege}. {$fix}";
    }

    /**
     * @return array{host: string, port: int, username: string, password: string, tls: bool}
     */
    protected function credentialsOf(MigrationSource $source): array
    {
        return [
            'host' => $source->host,
            'port' => $source->port,
            'username' => $source->username,
            'password' => $source->password,
            'tls' => $source->tls,
        ];
    }

    private function inAppDatabaseOf(MigrationSource $source): ?Database
    {
        return Database::query()->where('engine', $this->database->engine)->get()
            ->first(fn (Database $database): bool => $database->isReachedByDefaultUserAt($source->host, $source->username));
    }
}
