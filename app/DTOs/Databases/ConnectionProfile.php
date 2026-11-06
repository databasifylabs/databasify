<?php

declare(strict_types=1);

namespace App\DTOs\Databases;

final readonly class ConnectionProfile
{
    /**
     * @param  array{host: string, port: string, username: ?string, password: string, database: ?string}  $envKeys
     */
    public function __construct(
        public string $uriScheme,
        public string $uriQuery,
        public string $tlsParameter,
        public string $tlsMode,
        public string $command,
        public ?string $fixedUsername,
        public bool $selectsDatabase,
        public array $envKeys,
        public ?string $envNote = null,
        public ?string $commandNote = null,
        public ?ServiceConnection $service = null,
    ) {}

    public function withService(ServiceConnection $service): self
    {
        return clone ($this, ['service' => $service]);
    }

    /**
     * @return array{uri_scheme: string, uri_query: string, tls_parameter: string, tls_mode: string, command: string, fixed_username: ?string, selects_database: bool, env_keys: array{host: string, port: string, username: ?string, password: string, database: ?string}, env_note: ?string, command_note: ?string, service: array{uri_scheme: string, uri_query: string, uri_port: false, command: string, env_note: string, standby_uri_query: string, standby_command: string}|null}
     */
    public function toArray(): array
    {
        return [
            'uri_scheme' => $this->uriScheme,
            'uri_query' => $this->uriQuery,
            'tls_parameter' => $this->tlsParameter,
            'tls_mode' => $this->tlsMode,
            'command' => $this->command,
            'fixed_username' => $this->fixedUsername,
            'selects_database' => $this->selectsDatabase,
            'env_keys' => $this->envKeys,
            'env_note' => $this->envNote,
            'command_note' => $this->commandNote,
            'service' => $this->service?->toArray(),
        ];
    }
}
