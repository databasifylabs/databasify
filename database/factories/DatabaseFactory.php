<?php

declare(strict_types=1);

namespace Database\Factories;

use App\DTOs\Databases\DatabaseSettings;
use App\DTOs\Databases\EngineImage;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\DatabaseStatus;
use App\Models\Database;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Database>
 */
class DatabaseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => fn (): string => ProjectFactory::defaultProject()->id,
            'name' => fake()->unique()->slug(2),
            'engine' => 'postgres',
            'version_line' => '18',
            'mode' => DatabaseMode::Single,
            'status' => DatabaseStatus::Ready,
            'image' => new EngineImage('ghcr.io/databasifylabs/engine-postgres', 'sha256:'.str_repeat('a', 64), '18.1', '0.2.2'),
            'maintenance_day' => 0,
            'maintenance_hour' => 3,
            'settings' => new DatabaseSettings(secrets: ['DB_USERNAME' => 'databasify', 'DB_PASSWORD' => 'secret-password', 'DB_DATABASE' => 'databasify']),
            'config_fields' => [],
        ];
    }

    public function status(DatabaseStatus $status): static
    {
        return $this->state(['status' => $status]);
    }

    public function engine(string $engine, string $versionLine): static
    {
        return $this->state(['engine' => $engine, 'version_line' => $versionLine]);
    }

    public function highlyAvailable(): static
    {
        return $this->state(['mode' => DatabaseMode::HighAvailability]);
    }
}
