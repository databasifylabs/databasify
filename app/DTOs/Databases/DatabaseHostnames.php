<?php

declare(strict_types=1);

namespace App\DTOs\Databases;

use App\DTOs\Dns\HostPair;

final readonly class DatabaseHostnames
{
    public const string REPLICA_PREFIX = 'replica-';

    public function __construct(
        public HostPair $primary,
        public HostPair $replica,
    ) {}

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return array_merge($this->primary->all(), $this->replica->all());
    }
}
