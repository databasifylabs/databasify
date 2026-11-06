<?php

declare(strict_types=1);

namespace App\Providers;

use App\DnsProviders\Drivers\Cloudflare\Cloudflare;
use App\DTOs\Forms\DynamicField;
use App\DTOs\Forms\DynamicForm;
use App\Plugins\RegisterDnsProvider;
use Illuminate\Support\ServiceProvider;

class DnsProviderServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->cloudflare();
    }

    private function cloudflare(): void
    {
        RegisterDnsProvider::make(Cloudflare::id())
            ->label('Cloudflare')
            ->handler(Cloudflare::class)
            ->form(DynamicForm::make([DynamicField::make('token')->password()->label('API token')]))
            ->instructions([
                'Open [My Profile → API Tokens](https://dash.cloudflare.com/profile/api-tokens) and create a token.',
                'Give it these permissions for the domains you want to use: `Zone → Zone → Read` `Zone → DNS → Edit`',
                'Copy the token and paste it here. Cloudflare shows it only once.',
            ])
            ->register();
    }
}
