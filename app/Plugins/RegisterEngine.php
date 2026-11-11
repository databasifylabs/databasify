<?php

declare(strict_types=1);

namespace App\Plugins;

use App\DTOs\Databases\ConnectionProfile;
use App\DTOs\Databases\ServiceConnection;

final class RegisterEngine extends AbstractProviderRegistrar
{
    public function category(string $category): static
    {
        return $this->set('category', $category);
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

    public function failoverHint(string $hint): static
    {
        return $this->set('failover_hint', $hint);
    }

    public function serviceRecord(string $prefix, string $options): static
    {
        return $this->set('service_record', ['prefix' => $prefix, 'options' => $options]);
    }

    public function publicCertificate(): static
    {
        return $this->set('public_certificate', true);
    }

    public function migrationSource(int $defaultPort, bool $credentialsRequired = true, bool $selectsDatabases = true): static
    {
        return $this->set('migration_source', ['default_port' => $defaultPort, 'credentials_required' => $credentialsRequired, 'selects_databases' => $selectsDatabases]);
    }

    public function connection(ConnectionProfile $profile, ?ConnectionProfile $withPublicCertificate = null, ?ServiceConnection $service = null): static
    {
        return $this->set('connection', $profile->toArray())
            ->set('public_connection', $withPublicCertificate?->toArray())
            ->set('service_connection', $service?->toArray());
    }

    /**
     * @param  array<string, string>  $extra
     */
    public function environment(?string $laravelConnection, array $extra = []): static
    {
        return $this->set('environment', ['connection' => $laravelConnection, 'extra' => $extra]);
    }

    public function readOnlyNodesVote(): static
    {
        return $this->set('read_only_nodes_vote', true);
    }

    public function persistenceChoice(): static
    {
        return $this->set('persistence_choice', true);
    }

    protected function config(): string
    {
        return 'engine';
    }
}
