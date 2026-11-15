<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\NodeKind;
use App\Enums\Databases\NodeRole;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DatabaseNode>
 */
class DatabaseNodeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'database_id' => Database::factory(),
            'server_id' => Server::factory()->ready(),
            'position' => 1,
            'kind' => NodeKind::Member,
            'role' => NodeRole::Primary,
            'status' => DatabaseStatus::Ready,
            'missed_status_reads' => 0,
        ];
    }

    public function replica(int $position = 2): static
    {
        return $this->state(['role' => NodeRole::Replica, 'position' => $position]);
    }

    public function readOnly(int $position = 2): static
    {
        return $this->state(['kind' => NodeKind::ReadOnly, 'role' => NodeRole::Replica, 'position' => $position]);
    }
}
