<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Recovery\ArchiveHealth;
use App\Models\PointInTimeRecovery;
use App\Models\StorageProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PointInTimeRecovery>
 */
class PointInTimeRecoveryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'storage_provider_id' => StorageProvider::factory(),
            'path' => 'postgres/main-1761475200',
            'retention_days' => 7,
            'backup_hour' => 3,
            'health' => ArchiveHealth::Pending,
        ];
    }

    public function restorable(): static
    {
        return $this->state(fn (): array => [
            'health' => ArchiveHealth::Healthy,
            'backups_count' => 2,
            'earliest_restorable_at' => now()->subDays(2),
            'latest_restorable_at' => now()->subMinute(),
            'last_backup_at' => now()->subHours(5),
            'checked_at' => now(),
        ]);
    }
}
