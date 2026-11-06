<?php

declare(strict_types=1);

namespace App\Actions\Providers;

use App\Actions\Servers\QueueProvisioning;
use App\Models\Server;
use App\Models\ServerProvider;
use RuntimeException;
use Throwable;

final readonly class LaunchProviderServer
{
    public function __construct(
        private OrderProviderInstance $order,
        private AwaitProviderInstance $instance,
        private AwaitServerSsh $ssh,
        private QueueProvisioning $provisioning,
    ) {}

    public function handle(Server $server): void
    {
        $provider = $server->serverProvider ?? throw new RuntimeException("{$server->name} was not ordered from a provider.");

        try {
            $id = $this->order->handle($server, $provider);
            $this->instance->handle($server, $provider, $id);
            $this->ssh->handle($server);
        } catch (Throwable $exception) {
            $server->markFailed();

            throw $exception;
        }

        $this->provisioning->handle($server, disablePasswordLogin: true);
    }

    /**
     * @return list<string>
     */
    public static function logNamesFor(ServerProvider $provider): array
    {
        return [OrderProviderInstance::logNameFor($provider), AwaitProviderInstance::logNameFor($provider), AwaitServerSsh::LOG_NAME];
    }
}
