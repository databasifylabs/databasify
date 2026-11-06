<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DnsProvider;
use App\Models\DnsZone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DnsZone>
 */
class DnsZoneFactory extends Factory
{
    public function definition(): array
    {
        return [
            'dns_provider_id' => DnsProvider::factory(),
            'name' => fake()->unique()->domainName(),
            'external_id' => 'zone-1',
        ];
    }
}
