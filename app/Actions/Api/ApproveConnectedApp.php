<?php

declare(strict_types=1);

namespace App\Actions\Api;

use App\DTOs\Api\AccessGrant;
use App\Models\OAuthClient;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Server\Registrar;

final readonly class ApproveConnectedApp
{
    /**
     * @return list<non-empty-string>
     */
    public function handle(User $owner, OAuthClient $client, AccessGrant $grant): array
    {
        $scopes = [...$grant->scopes, Registrar::OAUTH_SCOPE];

        DB::transaction(function () use ($owner, $client, $grant, $scopes): void {
            $client->owner()->associate($owner);
            $client->forceFill(['scopes' => $scopes, 'approved_at' => now(), 'all_projects' => $grant->projectIds === null])->save();
            $client->projects()->sync($grant->projectIds ?? []);
        });

        return $scopes;
    }
}
