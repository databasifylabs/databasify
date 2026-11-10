<?php

declare(strict_types=1);

namespace App\Engines\Data;

use App\DTOs\Databases\DatabaseSettings;
use App\Engines\EngineSecrets;

final readonly class Secrets
{
    public function __construct(
        public ?string $admin,
        public ?string $replication,
        public ?string $agent,
    ) {}

    public static function of(DatabaseSettings $settings): self
    {
        $secret = fn (string $name): ?string => $settings->secret($name) ?: null;

        return new self($secret(EngineSecrets::ADMIN), $secret(EngineSecrets::REPLICATION), $secret(EngineSecrets::AGENT));
    }

    /**
     * @return array{admin: ?string, replication: ?string, agent: ?string}
     */
    public function toPayload(): array
    {
        return ['admin' => $this->admin, 'replication' => $this->replication, 'agent' => $this->agent];
    }
}
