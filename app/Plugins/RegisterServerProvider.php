<?php

declare(strict_types=1);

namespace App\Plugins;

final class RegisterServerProvider extends AbstractProviderRegistrar
{
    public function serverLabel(string $label): static
    {
        return $this->set('server_label', $label);
    }

    public function sshUser(string $user): static
    {
        return $this->set('ssh_user', $user);
    }

    /**
     * @param  array<string, int|string>  $images
     */
    public function images(array $images): static
    {
        return $this->set('images', $images);
    }

    protected function config(): string
    {
        return 'server-provider';
    }
}
