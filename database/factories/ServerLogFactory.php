<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Servers\ServerLogStatus;
use App\Models\Server;
use App\Models\ServerLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServerLog>
 */
class ServerLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'name' => 'Update packages',
            'status' => ServerLogStatus::Succeeded,
            'output' => "done\n",
            'exit_code' => 0,
        ];
    }
}
