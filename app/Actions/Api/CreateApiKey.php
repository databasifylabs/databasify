<?php

declare(strict_types=1);

namespace App\Actions\Api;

use App\DTOs\Api\CreatedApiKey;
use App\DTOs\Api\NewApiKey;
use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use LogicException;

final readonly class CreateApiKey
{
    public function __construct(private EnsurePersonalAccessClient $ensureClient) {}

    public function handle(User $owner, NewApiKey $data): CreatedApiKey
    {
        $this->ensureClient->handle();
        Passport::personalAccessTokensExpireIn($data->expiry->interval());

        return DB::transaction(function () use ($owner, $data): CreatedApiKey {
            $result = $owner->createToken($data->name, $data->scopes);
            $key = $result->getToken();

            if (! $key instanceof ApiKey) {
                throw new LogicException('Passport must use the ApiKey token model.');
            }

            $key->forceFill(['all_projects' => $data->projectIds === null])->save();
            $key->projects()->sync($data->projectIds ?? []);

            return new CreatedApiKey($key, $result->accessToken);
        });
    }
}
