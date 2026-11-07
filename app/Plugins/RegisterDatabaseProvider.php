<?php

declare(strict_types=1);

namespace App\Plugins;

final class RegisterDatabaseProvider extends AbstractProviderRegistrar
{
    public function category(string $category): static
    {
        return $this->set('category', $category);
    }

    public function stopSeconds(int $seconds): static
    {
        return $this->set('stop_seconds', $seconds);
    }

    public function image(string $repository): static
    {
        return $this->set('image', $repository);
    }

    /**
     * @param  list<array{value: string, label: string, recovery?: bool}>  $lines
     */
    public function versions(string $default, array $lines): static
    {
        return $this->set('default_line', $default)->set('lines', $lines);
    }

    /**
     * @param  array<array-key, array<string, string>>  $overrides
     */
    public function scripts(string $path, array $overrides = []): static
    {
        return $this->set('scripts', $path)->set('script_overrides', $overrides);
    }

    public function directory(string $path): static
    {
        return $this->set('directory', $path);
    }

    public function systemUser(string $user): static
    {
        return $this->set('system_user', $user);
    }

    public function port(int $port): static
    {
        return $this->set('port', $port);
    }

    public function poolPort(int $port): static
    {
        return $this->set('pool_port', $port);
    }

    /**
     * @param  list<int>  $ports
     * @param  array<string, list<int>>  $lines
     */
    public function peerPorts(array $ports, array $lines = []): static
    {
        return $this->set('peer_ports', $ports)->set('line_peer_ports', $lines);
    }

    /**
     * @param  list<string>  $files
     */
    public function nodeCertificates(array $files): static
    {
        return $this->set('node_certificates', $files);
    }

    public function failoverHint(string $hint): static
    {
        return $this->set('failover_hint', $hint);
    }

    public function persistenceChoice(): static
    {
        return $this->set('persistence_choice', true);
    }

    public function publicCertificate(): static
    {
        return $this->set('public_certificate', true);
    }

    public function serviceRecord(string $prefix, string $options): static
    {
        return $this->set('service_record', ['prefix' => $prefix, 'options' => $options]);
    }

    public function rsaServerKey(): static
    {
        return $this->set('rsa_server_key', true);
    }

    protected function config(): string
    {
        return 'database-provider';
    }
}
