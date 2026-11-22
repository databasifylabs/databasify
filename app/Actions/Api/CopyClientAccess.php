<?php

declare(strict_types=1);

namespace App\Actions\Api;

use App\Models\ApiKey;
use App\Models\OAuthClient;
use Illuminate\Support\Facades\DB;

final readonly class CopyClientAccess
{
    public function handle(OAuthClient $client, ApiKey $token): void
    {
        DB::transaction(function () use ($client, $token): void {
            $token->forceFill(['all_projects' => $client->all_projects])->save();
            $token->projects()->sync($client->projects()->pluck('projects.id')->all());
        });
    }
}
