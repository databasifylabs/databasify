<?php

declare(strict_types=1);

namespace App\Engines\Data;

final readonly class Member
{
    public function __construct(
        public string $ip,
        public int $position,
        public bool $voting,
        public int $cpus,
        public int $memoryMb,
        public int $diskGb,
    ) {}

    /**
     * @return array{ip: string, position: int, voting: bool, cpus: int, memoryMb: int, diskGb: int}
     */
    public function toPayload(): array
    {
        return ['ip' => $this->ip, 'position' => $this->position, 'voting' => $this->voting, 'cpus' => $this->cpus, 'memoryMb' => $this->memoryMb, 'diskGb' => $this->diskGb];
    }
}
