<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Servers\ServerStatus;
use App\Models\Network;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Server>
 */
class ServerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => fn (): string => ProjectFactory::defaultProject()->id,
            'name' => fake()->unique()->slug(2),
            'public_ip' => fake()->unique()->ipv4(),
            'private_ip' => fake()->unique()->localIpv4(),
            'network_id' => fn (): string => Network::query()->withoutProject()->firstOrCreate(['name' => 'shared'], ['project_id' => ProjectFactory::defaultProject()->id, 'cidr' => '10.0.0.0/8'])->id,
            'ssh_port' => 22,
            'ssh_user' => 'root',
            'status' => ServerStatus::Pending,
        ];
    }

    public function ready(): static
    {
        return $this->state(['status' => ServerStatus::Ready, 'host_key_fingerprint' => 'SHA256:test']);
    }

    public function onNetwork(?Network $network): static
    {
        return $this->state(['network_id' => $network?->id]);
    }
}
