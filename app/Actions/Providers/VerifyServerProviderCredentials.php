<?php

declare(strict_types=1);

namespace App\Actions\Providers;

use App\Exceptions\Providers\ProviderRequestFailed;
use App\Models\ServerProvider;
use App\ServerProviders\Contracts\ServerDriver;
use Illuminate\Validation\ValidationException;

final readonly class VerifyServerProviderCredentials
{
    public function handle(ServerProvider $provider): ServerDriver
    {
        $driver = $provider->provider();

        try {
            $driver->verify();
        } catch (ProviderRequestFailed $exception) {
            throw ValidationException::withMessages(['credentials' => "{$provider->label()} refused these credentials: {$exception->getMessage()}"]);
        }

        return $driver;
    }
}
