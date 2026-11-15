<?php

declare(strict_types=1);

namespace Database\Factories;

use App\DTOs\Migrations\MigrationSource;
use App\Enums\Migrations\MigrationMode;
use App\Enums\Migrations\MigrationStatus;
use App\Models\Database;
use App\Models\DatabaseMigration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DatabaseMigration>
 */
class DatabaseMigrationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'database_id' => Database::factory(),
            'mode' => MigrationMode::Continuous,
            'status' => MigrationStatus::Syncing,
            'source' => new MigrationSource('source.example.com', 5432, 'migrator', 'secret', databases: ['shop']),
            'started_at' => now(),
        ];
    }

    public function status(MigrationStatus $status): static
    {
        return $this->state(['status' => $status]);
    }

    public function cleanupFailed(): static
    {
        return $this->state(['status' => MigrationStatus::Failed, 'finished_at' => now(), 'cleanup_failed_at' => now()]);
    }
}
