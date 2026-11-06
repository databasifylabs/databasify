<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ServerProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServerProvider>
 */
class ServerProviderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->slug(2),
            'type' => 'digitalocean',
            'credentials' => ['token' => 'test-token'],
        ];
    }
}
