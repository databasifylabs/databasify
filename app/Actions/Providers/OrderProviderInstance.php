<?php

declare(strict_types=1);

namespace App\Actions\Providers;

use App\DTOs\Providers\NewProviderServer;
use App\Models\Server;
use App\Models\ServerProvider;
use App\ServerProviders\Contracts\ServerDriver;
use App\Services\Ssh\AppSshKey;
use App\Support\Ssh\Fingerprint;
use RuntimeException;

final readonly class OrderProviderInstance
{
    public function __construct(private AppSshKey $appKey) {}

    public function handle(Server $server, ServerProvider $provider): string
    {
        $log = $server->startLog(self::logNameFor($provider));
        $order = $this->orderOf($server, $provider);
        $log->write("Ordering {$order->name}: plan {$order->plan} in {$order->region}, image {$order->image}".PHP_EOL);

        $id = $provider->provider()->createServer($order);
        $server->update(['provider_server_id' => $id]);
        $log->write("{$provider->label()} created instance {$id}".PHP_EOL);
        $log->finish(0);

        return $id;
    }

    public static function logNameFor(ServerProvider $provider): string
    {
        return "Order server from {$provider->label()}";
    }

    private function orderOf(Server $server, ServerProvider $provider): NewProviderServer
    {
        $publicKey = $this->appKey->get()->public_key;

        return new NewProviderServer(
            name: $server->name,
            region: $server->region ?? throw new RuntimeException("{$server->name} has no region."),
            plan: $server->plan ?? throw new RuntimeException("{$server->name} has no plan."),
            image: $this->imageFor($server, $provider),
            networkExternalId: $server->network?->external_id,
            sshKeyId: $this->sshKeyIdOn($provider->provider(), $publicKey),
            publicKey: $publicKey,
        );
    }

    private function imageFor(Server $server, ServerProvider $provider): int|string
    {
        $os = $server->image ?? throw new RuntimeException("{$server->name} has no operating system.");

        return $provider->provider()->image($os) ?? throw new RuntimeException("{$provider->label()} has no {$os->label()} image.");
    }

    private function sshKeyIdOn(ServerDriver $driver, string $publicKey): string
    {
        return $driver->findKey($publicKey) ?? $driver->createKey('databasify-'.substr(str_replace(':', '', Fingerprint::md5($publicKey)), 0, 12), $publicKey);
    }
}
