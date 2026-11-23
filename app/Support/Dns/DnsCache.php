<?php

declare(strict_types=1);

namespace App\Support\Dns;

use App\Enums\Dns\DnsRecordType;
use App\Models\Database;
use App\Models\DnsZone;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

final readonly class DnsCache
{
    private const int DATABASE_LOCK_SECONDS = 300;

    private const int ZONE_CERTIFICATE_LOCK_SECONDS = 600;

    private const int RECORD_LOCK_SECONDS = 120;

    private const int RECHECK_SECONDS = 3600;

    private const int REMIND_SECONDS = 86400;

    public function databaseLock(Database $database): Lock
    {
        return Cache::lock("database-dns:{$database->id}", self::DATABASE_LOCK_SECONDS);
    }

    public function zoneCertificateLock(DnsZone $zone): Lock
    {
        return Cache::lock("zone-certificate:{$zone->id}", self::ZONE_CERTIFICATE_LOCK_SECONDS);
    }

    public function recordLock(DnsRecordType $type, string $name): Lock
    {
        return Cache::lock("{$this->recordKeyOf($type, $name)}:lock", self::RECORD_LOCK_SECONDS);
    }

    /**
     * @param  list<string>  $values
     */
    public function published(DnsRecordType $type, string $name, array $values): bool
    {
        return Cache::get($this->recordKeyOf($type, $name)) === $values;
    }

    /**
     * @param  list<string>  $values
     */
    public function markPublished(DnsRecordType $type, string $name, array $values): void
    {
        Cache::put($this->recordKeyOf($type, $name), $values, self::RECHECK_SECONDS);
    }

    public function firstSyncFailure(Database $database): bool
    {
        return Cache::add($this->syncFailureKeyOf($database), true, self::REMIND_SECONDS);
    }

    public function clearSyncFailure(Database $database): void
    {
        Cache::forget($this->syncFailureKeyOf($database));
    }

    private function recordKeyOf(DnsRecordType $type, string $name): string
    {
        return match ($type) {
            DnsRecordType::A => "dns:{$name}",
            DnsRecordType::Srv => "dns-srv:{$name}",
            DnsRecordType::Txt => "dns-txt:{$name}",
        };
    }

    private function syncFailureKeyOf(Database $database): string
    {
        return "dns-sync-failed:{$database->id}";
    }
}
