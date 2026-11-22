<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\OAuthClient;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OAuthClient>
 */
class OAuthClientFactory extends Factory
{
    protected $model = OAuthClient::class;

    public function definition(): array
    {
        return [
            'owner_id' => null,
            'owner_type' => null,
            'name' => 'Claude',
            'secret' => null,
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'revoked' => false,
            'all_projects' => true,
        ];
    }

    public function approvedBy(User $user): static
    {
        return $this->state([
            'owner_id' => $user->id,
            'owner_type' => $user->getMorphClass(),
            'approved_at' => now(),
        ]);
    }

    public function withScopes(string ...$scopes): static
    {
        return $this->state(['scopes' => $scopes]);
    }

    public function limitedTo(Project ...$projects): static
    {
        return $this->state(['all_projects' => false])
            ->afterCreating(fn (OAuthClient $client) => $client->projects()->attach(array_map(fn (Project $project): string => $project->id, $projects)));
    }

    public function named(string $name, string $redirect): static
    {
        return $this->state(['name' => $name, 'redirect_uris' => [$redirect]]);
    }

    public function revoked(): static
    {
        return $this->state(['revoked' => true]);
    }
}
