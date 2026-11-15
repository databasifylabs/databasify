<?php

declare(strict_types=1);

namespace App\Actions\Providers;

use App\DTOs\Providers\NewProviderServer;
use App\Models\Server;
use App\Models\ServerProvider;
use App\Services\Ssh\AppSshKey;
use RuntimeException;

final readonly class OrderProviderInstance
{
    public function __construct(private AppSshKey $appKey, private RegisterProviderKey $registerKey) {}

    public function handle(Server $server, ServerProvider $provider): string
    {
        $log = $server->startLog(self::logNameFor($provider));
        $driver = $provider->provider();
        $publicKey = $this->appKey->get()->public_key;
        $os = $server->image ?? throw new RuntimeException("{$server->name} has no operating system.");
        $order = new NewProviderServer(
            name: $server->name,
            region: $server->region ?? throw new RuntimeException("{$server->name} has no region."),
            plan: $server->plan ?? throw new RuntimeException("{$server->name} has no plan."),
            image: $driver->image($os) ?? throw new RuntimeException("{$provider->label()} has no {$os->label()} image."),
            networkExternalId: $server->network?->external_id,
            sshKeyId: $this->registerKey->handle($driver, $publicKey),
            publicKey: $publicKey,
        );
        $log->write("Ordering {$order->name}: plan {$order->plan} in {$order->region}, image {$order->image}".PHP_EOL);

        $id = $driver->createServer($order);
        $server->update(['provider_server_id' => $id]);
        $log->write("{$provider->label()} created instance {$id}".PHP_EOL);
        $log->finish(0);

        return $id;
    }

    public static function logNameFor(ServerProvider $provider): string
    {
        return "Order server from {$provider->label()}";
    }
}
