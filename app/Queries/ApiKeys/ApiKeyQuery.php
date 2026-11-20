<?php

declare(strict_types=1);

namespace App\Queries\ApiKeys;

use App\Models\ApiKey;
use App\Models\User;
use App\Queries\Query;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;

/**
 * @extends Query<ApiKey>
 */
final class ApiKeyQuery extends Query
{
    private function __construct(private readonly User $owner) {}

    public static function of(User $owner): self
    {
        return new self($owner);
    }

    public function builder(): Builder
    {
        $personalClientIds = Passport::client()->newQuery()->get()
            ->filter(fn (Client $client): bool => $client->hasGrantType('personal_access'))
            ->modelKeys();

        return ApiKey::query()
            ->with('projects')
            ->where('user_id', $this->owner->id)
            ->whereIn('client_id', $personalClientIds)
            ->where('revoked', false)
            ->latest();
    }
}
