<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DnsProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DnsProvider>
 */
class DnsProviderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->slug(2),
            'type' => 'cloudflare',
            'credentials' => ['token' => 'cf-token'],
        ];
    }
}
