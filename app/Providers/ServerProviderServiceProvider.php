<?php

declare(strict_types=1);

namespace App\Providers;

use App\DTOs\Forms\DynamicField;
use App\DTOs\Forms\DynamicForm;
use App\Plugins\RegisterServerProvider;
use App\ServerProviders\Drivers\DigitalOcean\DigitalOcean;
use App\ServerProviders\Drivers\Hetzner\Hetzner;
use App\ServerProviders\Drivers\Linode\Linode;
use App\ServerProviders\Drivers\Vultr\Vultr;
use Illuminate\Support\ServiceProvider;

class ServerProviderServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->digitalOcean();
        $this->hetzner();
        $this->vultr();
        $this->linode();
    }

    private function digitalOcean(): void
    {
        $this->server(DigitalOcean::id(), 'DigitalOcean', DigitalOcean::class, 'droplet')
            ->instructions([
                'Open [API → Tokens](https://cloud.digitalocean.com/account/api/tokens) and generate a new token.',
                'Choose custom scopes and tick these, or give it full access: `account:read` `droplet:create` `droplet:read` `droplet:delete` `ssh_key:create` `ssh_key:read` `vpc:read` `regions:read` `sizes:read` `image:read` `tag:create`',
                'Copy the token and paste it here. DigitalOcean shows it only once.',
            ])
            ->images(['ubuntu-24.04' => 'ubuntu-24-04-x64', 'ubuntu-26.04' => 'ubuntu-26-04-x64', 'debian-13' => 'debian-13-x64', 'rocky-9' => 'rockylinux-9-x64', 'almalinux-9' => 'almalinux-9-x64'])
            ->register();
    }

    private function hetzner(): void
    {
        $this->server(Hetzner::id(), 'Hetzner Cloud', Hetzner::class, 'server')
            ->instructions([
                'Open [your project → Security → API tokens](https://console.hetzner.cloud/projects) and generate a new token.',
                'Give it this permission: `Read & Write`',
                'Copy the token and paste it here. Hetzner shows it only once.',
            ])
            ->images(['ubuntu-24.04' => 'ubuntu-24.04', 'ubuntu-26.04' => 'ubuntu-26.04', 'debian-12' => 'debian-12', 'debian-13' => 'debian-13', 'rocky-9' => 'rocky-9', 'almalinux-9' => 'alma-9'])
            ->register();
    }

    private function vultr(): void
    {
        $this->server(Vultr::id(), 'Vultr', Vultr::class, 'instance', 'API key')
            ->instructions([
                'Open [Account → API](https://my.vultr.com/settings/#settingsapi) and enable the API.',
                'Under Access Control allow this address: `the IPv4 of the Databasify server, or all IPv4`',
                'Copy the API key and paste it here.',
            ])
            ->images(['ubuntu-24.04' => 2284, 'ubuntu-26.04' => 2760, 'debian-12' => 2136, 'debian-13' => 2625, 'rocky-9' => 1869, 'almalinux-9' => 1868])
            ->register();
    }

    private function linode(): void
    {
        $this->server(Linode::id(), 'Linode', Linode::class, 'Linode')
            ->instructions([
                'Open [Profile → API Tokens](https://cloud.linode.com/profile/tokens) and create a personal access token.',
                'Give it these scopes: `Linodes: Read/Write` `VPCs: Read/Write`',
                'Copy the token and paste it here. Linode shows it only once.',
            ])
            ->images(['ubuntu-24.04' => 'linode/ubuntu24.04', 'ubuntu-26.04' => 'linode/ubuntu26.04', 'debian-12' => 'linode/debian12', 'debian-13' => 'linode/debian13', 'rocky-9' => 'linode/rocky9', 'almalinux-9' => 'linode/almalinux9'])
            ->register();
    }

    /**
     * @param  class-string  $handler
     */
    private function server(string $id, string $label, string $handler, string $serverLabel, string $tokenLabel = 'API token'): RegisterServerProvider
    {
        return RegisterServerProvider::make($id)
            ->label($label)
            ->handler($handler)
            ->form(DynamicForm::make([DynamicField::make('token')->password()->label($tokenLabel)]))
            ->serverLabel($serverLabel)
            ->sshUser('root');
    }
}
