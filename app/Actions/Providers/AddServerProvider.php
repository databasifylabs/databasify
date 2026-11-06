<?php

declare(strict_types=1);

namespace App\Actions\Providers;

use App\DTOs\Providers\NewServerProvider;
use App\Models\ServerProvider;

final readonly class AddServerProvider
{
    public function __construct(private VerifyServerProviderCredentials $credentials) {}

    public function handle(NewServerProvider $data): ServerProvider
    {
        $provider = new ServerProvider($data->toRow());
        $this->credentials->handle($provider);
        $provider->save();

        return $provider;
    }
}
