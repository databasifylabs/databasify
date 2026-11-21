<?php

declare(strict_types=1);

namespace App\Queries\ApiKeys;

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Passport\AccessToken;

final readonly class CurrentApiKeyQuery
{
    private const string ATTRIBUTE = 'databasify.api_key';

    public function for(Request $request): ?ApiKey
    {
        if (! $request->attributes->has(self::ATTRIBUTE)) {
            $request->attributes->set(self::ATTRIBUTE, $this->load($request));
        }

        $key = $request->attributes->get(self::ATTRIBUTE);

        return $key instanceof ApiKey ? $key : null;
    }

    private function load(Request $request): ?ApiKey
    {
        $user = $request->user('api');
        $token = $user instanceof User ? $user->token() : null;

        if (! $token instanceof AccessToken || ! isset($token->oauth_access_token_id)) {
            return null;
        }

        return ApiKey::query()->find($token->oauth_access_token_id);
    }
}
