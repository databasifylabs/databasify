<?php

declare(strict_types=1);

namespace App\DTOs\Migrations;

use App\Support\Migrations\NameList;

final readonly class MigrationSource
{
    /**
     * @param  list<string>  $databases
     * @param  list<string>  $discovered
     */
    public function __construct(
        public string $host,
        public int $port,
        public string $username,
        public string $password,
        public bool $tls = true,
        public array $databases = [],
        public array $discovered = [],
    ) {}

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            host: is_string($data['host'] ?? null) ? $data['host'] : '',
            port: is_int($data['port'] ?? null) ? $data['port'] : 0,
            username: is_string($data['username'] ?? null) ? $data['username'] : '',
            password: is_string($data['password'] ?? null) ? $data['password'] : '',
            tls: ($data['tls'] ?? true) === true,
            databases: NameList::from($data['databases'] ?? null),
            discovered: NameList::from($data['discovered'] ?? null),
        );
    }

    /**
     * @return array{host: string, port: int, username: string, password: string, tls: bool, databases: list<string>, discovered: list<string>}
     */
    public function toArray(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'password' => $this->password,
            'tls' => $this->tls,
            'databases' => $this->databases,
            'discovered' => $this->discovered,
        ];
    }

    /**
     * @return list<string>
     */
    public function tablePatterns(): array
    {
        return array_map(fn (string $database): string => addcslashes($database, '\\%_').'.%', $this->databases);
    }

    /**
     * @param  list<string>  $databases
     */
    public function withDiscovered(array $databases): self
    {
        return clone ($this, ['discovered' => $databases]);
    }

    /**
     * @return list<string>
     */
    public function copiedDatabases(): array
    {
        return $this->databases !== [] ? $this->databases : $this->discovered;
    }

    public function address(): string
    {
        return "{$this->host}:{$this->port}";
    }
}
