<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\DTOs\Dns\DnsRecord;
use App\DTOs\Dns\ServiceTarget;
use App\Enums\Dns\DnsRecordType;
use App\Models\DnsZone;
use Closure;
use Illuminate\Support\Facades\Cache;

final readonly class DnsRecordPublisher
{
    private const int RECHECK_SECONDS = 3600;

    private const int LOCK_SECONDS = 120;

    private const int WAIT_SECONDS = 30;

    /**
     * @param  list<string>  $addresses
     */
    public function point(DnsZone $zone, string $name, array $addresses): void
    {
        $this->onceChanged("dns:{$name}", $addresses, fn () => $this->sync($zone, DnsRecordType::A, $name, $addresses));
    }

    /**
     * @param  list<ServiceTarget>  $targets
     */
    public function pointService(DnsZone $zone, string $name, array $targets): void
    {
        $wanted = array_map(fn (ServiceTarget $target): string => $target->key(), $targets);
        $contents = array_map(fn (ServiceTarget $target): string => $target->toContent(), $targets);

        $this->onceChanged("dns-srv:{$name}", $wanted, fn () => $this->sync($zone, DnsRecordType::Srv, $name, $contents));
    }

    public function describe(DnsZone $zone, string $host, ?string $value): void
    {
        $this->onceChanged("dns-txt:{$host}", $value ?? '', fn () => $this->sync($zone, DnsRecordType::Txt, $host, $value === null ? [] : [$value]));
    }

    public function addTxt(DnsZone $zone, string $name, string $value): void
    {
        $zone->provider->provider()->createRecord($zone->external_id, new DnsRecord(DnsRecordType::Txt, $name, $value));
    }

    public function clearTxt(DnsZone $zone, string $name): void
    {
        $this->sync($zone, DnsRecordType::Txt, $name, []);
    }

    /**
     * @param  list<string>  $contents
     */
    private function sync(DnsZone $zone, DnsRecordType $type, string $name, array $contents): void
    {
        $driver = $zone->provider->provider();
        $existing = $driver->records($zone->external_id, $type, $name);

        foreach ($existing as $record) {
            if ($record->managed && ! in_array($record->content, $contents, true)) {
                $driver->deleteRecord($zone->external_id, $record);
            }
        }

        $present = array_map(fn (DnsRecord $record): string => $record->content, $existing);

        foreach (array_diff($contents, $present) as $content) {
            $driver->createRecord($zone->external_id, new DnsRecord($type, $name, $content));
        }
    }

    /**
     * @param  list<string>|string  $wanted
     */
    private function onceChanged(string $key, array|string $wanted, Closure $apply): void
    {
        Cache::lock("{$key}:lock", self::LOCK_SECONDS)->block(self::WAIT_SECONDS, function () use ($key, $wanted, $apply): void {
            if (Cache::get($key) === $wanted) {
                return;
            }

            $apply();
            Cache::put($key, $wanted, self::RECHECK_SECONDS);
        });
    }
}
