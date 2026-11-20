<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ApiKey;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Laravel\Passport\Client;

/**
 * @extends Factory<ApiKey>
 */
class ApiKeyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'id' => bin2hex(random_bytes(40)),
            'user_id' => User::factory(),
            'client_id' => Client::factory()->asPersonalAccessTokenClient(),
            'name' => 'ci',
            'scopes' => [],
            'revoked' => false,
            'all_projects' => true,
            'expires_at' => now()->addDays(30),
        ];
    }

    public function ownedBy(User $user): static
    {
        return $this->state(['user_id' => $user->id]);
    }

    public function withScopes(string ...$scopes): static
    {
        return $this->state(['scopes' => $scopes]);
    }

    public function limitedTo(Project ...$projects): static
    {
        return $this->state(['all_projects' => false])
            ->afterCreating(fn (ApiKey $key) => $key->projects()->attach(array_map(fn (Project $project): string => $project->id, $projects)));
    }

    public function revoked(): static
    {
        return $this->state(['revoked' => true]);
    }
}
