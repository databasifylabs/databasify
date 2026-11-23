<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\DTOs\Dns\DnsRecord;
use App\DTOs\Dns\ServiceTarget;
use App\Enums\Dns\DnsRecordType;
use App\Models\DnsZone;
use App\Support\Dns\DnsCache;
use Closure;

final readonly class DnsRecordPublisher
{
    private const int WAIT_SECONDS = 30;

    public function __construct(private DnsCache $cache) {}

    /**
     * @param  list<string>  $addresses
     */
    public function point(DnsZone $zone, string $name, array $addresses): void
    {
        $this->onceChanged(DnsRecordType::A, $name, $addresses, fn () => $this->sync($zone, DnsRecordType::A, $name, $addresses));
    }

    /**
     * @param  list<ServiceTarget>  $targets
     */
    public function pointService(DnsZone $zone, string $name, array $targets): void
    {
        $wanted = array_map(fn (ServiceTarget $target): string => $target->key(), $targets);
        $contents = array_map(fn (ServiceTarget $target): string => $target->toContent(), $targets);

        $this->onceChanged(DnsRecordType::Srv, $name, $wanted, fn () => $this->sync($zone, DnsRecordType::Srv, $name, $contents));
    }

    public function describe(DnsZone $zone, string $host, ?string $value): void
    {
        $values = $value === null ? [] : [$value];

        $this->onceChanged(DnsRecordType::Txt, $host, $values, fn () => $this->sync($zone, DnsRecordType::Txt, $host, $values));
    }

    public function clear(DnsZone $zone, DnsRecordType $type, string $name): void
    {
        $this->cache->recordLock($type, $name)->block(self::WAIT_SECONDS, function () use ($zone, $type, $name): void {
            $this->sync($zone, $type, $name, []);
            $this->cache->markPublished($type, $name, []);
        });
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
     * @param  list<string>  $wanted
     */
    private function onceChanged(DnsRecordType $type, string $name, array $wanted, Closure $apply): void
    {
        $this->cache->recordLock($type, $name)->block(self::WAIT_SECONDS, function () use ($type, $name, $wanted, $apply): void {
            if ($this->cache->published($type, $name, $wanted)) {
                return;
            }

            $apply();
            $this->cache->markPublished($type, $name, $wanted);
        });
    }
}
