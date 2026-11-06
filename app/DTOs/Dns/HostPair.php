<?php

declare(strict_types=1);

namespace App\DTOs\Dns;

use App\Models\DnsZone;

final readonly class HostPair
{
    private const string PRIVATE_PREFIX = 'private-';

    public function __construct(
        public string $public,
        public string $private,
    ) {}

    public static function under(DnsZone $zone, string $label): self
    {
        return new self($zone->hostFor($label), $zone->hostFor(self::PRIVATE_PREFIX.$label));
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return [$this->public, $this->private];
    }
}
