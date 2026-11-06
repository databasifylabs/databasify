<?php

declare(strict_types=1);

namespace App\DTOs\Servers;

use App\Enums\Servers\OperatingSystem;
use App\Enums\Servers\SelinuxMode;
use App\Exceptions\Servers\UnsupportedOperatingSystem;
use App\Support\Networks\Cidr;
use JsonException;

final readonly class ServerFacts
{
    /**
     * @param  list<string>  $subnets
     */
    public function __construct(
        public string $os,
        public int $cpus,
        public int $memoryMb,
        public int $diskGb,
        public ?OperatingSystem $system = null,
        public SelinuxMode $selinux = SelinuxMode::Disabled,
        public array $subnets = [],
    ) {}

    public static function fromJson(string $output): self
    {
        $data = self::lastJsonLineOf($output);

        return new self(
            os: self::stringIn($data, 'os') ?? 'unknown',
            cpus: self::intIn($data, 'cpus') ?? 0,
            memoryMb: self::intIn($data, 'memory_mb') ?? 0,
            diskGb: self::intIn($data, 'disk_gb') ?? 0,
            system: OperatingSystem::fromRelease(self::stringIn($data, 'id') ?? '', self::stringIn($data, 'version_id') ?? ''),
            selinux: SelinuxMode::fromGetenforce(self::stringIn($data, 'selinux') ?? ''),
            subnets: self::subnetsIn($data),
        );
    }

    public function supportedSystem(): OperatingSystem
    {
        return $this->system ?? throw UnsupportedOperatingSystem::running($this->os);
    }

    /**
     * @return array{os: string, cpus: int, memory_mb: int, disk_gb: int, interface_subnets: list<string>}
     */
    public function toRow(): array
    {
        return ['os' => $this->os, 'cpus' => $this->cpus, 'memory_mb' => $this->memoryMb, 'disk_gb' => $this->diskGb, 'interface_subnets' => $this->subnets];
    }

    /**
     * @return array<mixed>
     */
    private static function lastJsonLineOf(string $output): array
    {
        $line = collect(explode("\n", trim($output)))->last(fn (string $line): bool => str_starts_with($line, '{')) ?? '{}';

        try {
            $data = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<mixed>  $data
     */
    private static function stringIn(array $data, string $key): ?string
    {
        return is_string($data[$key] ?? null) ? $data[$key] : null;
    }

    /**
     * @param  array<mixed>  $data
     */
    private static function intIn(array $data, string $key): ?int
    {
        return is_int($data[$key] ?? null) ? $data[$key] : null;
    }

    /**
     * @param  array<mixed>  $data
     * @return list<string>
     */
    private static function subnetsIn(array $data): array
    {
        $subnets = is_array($data['subnets'] ?? null) ? $data['subnets'] : [];

        return array_values(collect(array_filter($subnets, Cidr::isValid(...)))->map(Cidr::canonical(...))->unique()->all());
    }
}
