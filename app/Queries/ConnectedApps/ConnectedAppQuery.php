<?php

declare(strict_types=1);

namespace App\Queries\ConnectedApps;

use App\Models\OAuthClient;
use App\Models\User;
use App\Queries\Query;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Query<OAuthClient>
 */
final class ConnectedAppQuery extends Query
{
    private function __construct(private readonly User $owner) {}

    public static function of(User $owner): self
    {
        return new self($owner);
    }

    public function builder(): Builder
    {
        return OAuthClient::query()
            ->with('projects')
            ->withMax('tokens', 'last_used_at')
            ->whereMorphedTo('owner', $this->owner)
            ->whereNotNull('approved_at')
            ->where('revoked', false)
            ->latest('approved_at');
    }
}
