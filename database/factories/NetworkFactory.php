<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Servers\NetworkKind;
use App\Models\Network;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Network>
 */
class NetworkFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => fn (): string => ProjectFactory::defaultProject()->id,
            'name' => fake()->unique()->slug(2),
            'cidr' => '10.0.0.0/8',
        ];
    }

    public function wireguard(int $block = 0): static
    {
        return $this->state(['name' => "databasify-{$block}", 'kind' => NetworkKind::Wireguard, 'cidr' => "100.64.{$block}.0/24", 'listen_port' => Network::LISTEN_PORT]);
    }
}
