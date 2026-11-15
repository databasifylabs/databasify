<?php

declare(strict_types=1);

namespace App\Actions\Providers;

use App\Exceptions\Providers\ProviderRequestFailed;
use App\ServerProviders\Contracts\ServerDriver;
use App\Support\Ssh\Fingerprint;

final readonly class RegisterProviderKey
{
    public function handle(ServerDriver $driver, string $publicKey): string
    {
        $existing = $driver->findKey($publicKey);

        if ($existing !== null) {
            return $existing;
        }

        try {
            return $driver->createKey('databasify-'.substr(str_replace(':', '', Fingerprint::md5($publicKey)), 0, 12), $publicKey);
        } catch (ProviderRequestFailed $failure) {
            return $driver->findKey($publicKey) ?? throw $failure;
        }
    }
}
