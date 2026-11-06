<?php

declare(strict_types=1);

namespace App\DnsProviders\Drivers\Cloudflare;

use App\DnsProviders\Drivers\AbstractDnsDriver;
use App\DTOs\Dns\DnsRecord;
use App\DTOs\Dns\DnsZoneData;
use App\DTOs\Dns\ServiceTarget;
use App\Enums\Dns\DnsRecordType;
use App\Exceptions\Providers\ProviderRequestFailed;
use App\Services\Http\Payload;
use App\Services\Http\TransientFailure;
use App\Services\Providers\ProviderHttp;
use Illuminate\Http\Client\PendingRequest;

final readonly class Cloudflare extends AbstractDnsDriver
{
    private const int TTL_SECONDS = 60;

    private const int PAGE_SIZE = 50;

    public static function id(): string
    {
        return 'cloudflare';
    }

    public function verify(): void
    {
        if ($this->get('/user/tokens/verify')->item('result')->string('status') !== 'active') {
            throw ProviderRequestFailed::from('Cloudflare', 403, 'The API token is not active.');
        }
    }

    public function zones(): array
    {
        $zones = [];

        for ($page = 1; ; $page++) {
            $body = $this->get('/zones', ['per_page' => self::PAGE_SIZE, 'page' => $page]);
            $zones = array_merge($zones, array_map(fn (Payload $zone): DnsZoneData => new DnsZoneData($zone->string('id'), $zone->string('name')), $body->items('result')));

            if ($page >= $body->item('result_info')->int('total_pages')) {
                return $zones;
            }
        }
    }

    public function records(string $zoneId, DnsRecordType $type, string $name): array
    {
        return array_map(
            fn (Payload $record): DnsRecord => new DnsRecord($type, $name, $this->contentOf($type, $record), $record->string('id'), $record->string('comment') === DnsRecord::MARKER),
            $this->get("/zones/{$zoneId}/dns_records", ['type' => $type->value, 'name' => $name])->items('result'),
        );
    }

    public function createRecord(string $zoneId, DnsRecord $record): void
    {
        $this->post("/zones/{$zoneId}/dns_records", array_merge(
            ['type' => $record->type->value, 'name' => $record->name],
            $this->bodyOf($record),
            ['ttl' => self::TTL_SECONDS, 'comment' => DnsRecord::MARKER],
        ));
    }

    public function deleteRecord(string $zoneId, DnsRecord $record): void
    {
        ProviderHttp::delete($this->retrying(true), "/zones/{$zoneId}/dns_records/{$record->id}");
    }

    private function contentOf(DnsRecordType $type, Payload $record): string
    {
        return match ($type) {
            DnsRecordType::A => $record->string('content'),
            DnsRecordType::Txt => trim($record->string('content'), '"'),
            DnsRecordType::Srv => (new ServiceTarget($record->item('data')->string('target'), $record->item('data')->int('port')))->toContent(),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function bodyOf(DnsRecord $record): array
    {
        $target = ServiceTarget::fromContent($record->content);

        return match ($record->type) {
            DnsRecordType::A => ['content' => $record->content, 'proxied' => false],
            DnsRecordType::Txt => ['content' => "\"{$record->content}\""],
            DnsRecordType::Srv => ['data' => ['priority' => 0, 'weight' => 0, 'port' => $target->port, 'target' => $target->host]],
        };
    }

    private function http(bool $allowMissing = false): PendingRequest
    {
        return ProviderHttp::for('Cloudflare', 'https://api.cloudflare.com/client/v4', $this->dnsProvider->credentials['token'] ?? '', fn (Payload $error): ?string => $error->stringOrNull('errors.0.message'), $allowMissing);
    }

    private function retrying(bool $allowMissing = false): PendingRequest
    {
        return TransientFailure::retrying($this->http($allowMissing));
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function get(string $path, array $query = []): Payload
    {
        return Payload::of(ProviderHttp::get($this->http(), $path, $query)->json());
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function post(string $path, array $body): Payload
    {
        return Payload::of(ProviderHttp::post($this->retrying(), $path, $body)->json());
    }
}
