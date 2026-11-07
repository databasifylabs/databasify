<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\StorageProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StorageProvider>
 */
class StorageProviderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => fn (): string => ProjectFactory::defaultProject()->id,
            'name' => fake()->unique()->slug(2),
            'endpoint' => 'https://s3.example.com',
            'region' => 'us-east-1',
            'bucket' => 'archives',
            'path_prefix' => '',
            'path_style' => true,
            'credentials' => ['access_key_id' => 'AKIDEXAMPLE', 'secret_access_key' => 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY'],
            'verified_at' => now(),
        ];
    }
}
