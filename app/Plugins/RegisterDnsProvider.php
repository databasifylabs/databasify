<?php

declare(strict_types=1);

namespace App\Plugins;

final class RegisterDnsProvider extends AbstractProviderRegistrar
{
    protected function config(): string
    {
        return 'dns-provider';
    }
}
