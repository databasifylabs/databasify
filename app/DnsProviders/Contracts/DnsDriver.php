<?php

declare(strict_types=1);

namespace App\DnsProviders\Contracts;

use App\DTOs\Dns\DnsRecord;
use App\DTOs\Dns\DnsZoneData;
use App\DTOs\Forms\DynamicForm;
use App\Enums\Dns\DnsRecordType;

interface DnsDriver
{
    public static function id(): string;

    public function label(): string;

    public function icon(): ?string;

    public function form(): DynamicForm;

    public function config(string $key, mixed $default = null): mixed;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;

    public function verify(): void;

    /**
     * @return list<DnsZoneData>
     */
    public function zones(): array;

    /**
     * @return list<DnsRecord>
     */
    public function records(string $zoneId, DnsRecordType $type, string $name): array;

    public function createRecord(string $zoneId, DnsRecord $record): void;

    public function deleteRecord(string $zoneId, DnsRecord $record): void;
}
