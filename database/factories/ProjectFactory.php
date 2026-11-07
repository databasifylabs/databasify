<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->unique()->city()];
    }

    public static function defaultProject(): Project
    {
        return Project::query()->oldest('id')->first() ?? Project::factory()->create();
    }
}
