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

    /**
     * @param  array<array-key, mixed>  $values
     */
    public static function fromArray(array $values): self
    {
        $text = fn (string $key): string => is_string($values[$key] ?? null) ? $values[$key] : '';
        $optional = fn (string $key): ?string => is_string($values[$key] ?? null) ? $values[$key] : null;
        $keys = is_array($values['env_keys'] ?? null) ? $values['env_keys'] : [];
        $key = fn (string $name): ?string => is_string($keys[$name] ?? null) ? $keys[$name] : null;

        return new self(
            uriScheme: $text('uri_scheme'),
            uriQuery: $text('uri_query'),
            tlsParameter: $text('tls_parameter'),
            tlsMode: $text('tls_mode'),
            command: $text('command'),
            fixedUsername: $optional('fixed_username'),
            selectsDatabase: ($values['selects_database'] ?? false) === true,
            envKeys: ['host' => $key('host') ?? '', 'port' => $key('port') ?? '', 'username' => $key('username'), 'password' => $key('password') ?? '', 'database' => $key('database')],
            envNote: $optional('env_note'),
            commandNote: $optional('command_note'),
            service: is_array($values['service'] ?? null) ? ServiceConnection::fromArray($values['service']) : null,
        );
    }

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
