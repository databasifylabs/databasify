<?php

declare(strict_types=1);

namespace App\Engines\Data;

use App\DTOs\Migrations\MigrationSource as AppMigrationSource;

final readonly class MigrationSource
{
    public function __construct(
        public string $host,
        public int $port,
        public string $username,
        public string $password,
        public bool $tls,
    ) {}

    public static function of(AppMigrationSource $source): self
    {
        return new self($source->host, $source->port, $source->username, $source->password, $source->tls);
    }

    /**
     * @return array{host: string, port: int, username: string, password: string, tls: bool}
     */
    public function toPayload(): array
    {
        return ['host' => $this->host, 'port' => $this->port, 'username' => $this->username, 'password' => $this->password, 'tls' => $this->tls];
    }
}
