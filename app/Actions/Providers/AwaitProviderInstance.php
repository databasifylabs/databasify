<?php

declare(strict_types=1);

namespace App\Actions\Providers;

use App\DTOs\Providers\ProviderServer;
use App\Models\Server;
use App\Models\ServerLog;
use App\Models\ServerProvider;
use App\Support\Providers\LaunchPolls;
use RuntimeException;

final readonly class AwaitProviderInstance
{
    public function handle(Server $server, ServerProvider $provider, string $id): void
    {
        $log = $server->startLog(self::logNameFor($provider));
        $server->recordLaunch($this->startedInstanceOf($provider, $id, $log));
        $log->finish(0);
    }

    public static function logNameFor(ServerProvider $provider): string
    {
        return "Wait for {$provider->label()} to start the server";
    }

    private function startedInstanceOf(ServerProvider $provider, string $id, ServerLog $log): ProviderServer
    {
        for ($poll = 1; $poll <= LaunchPolls::MAX; $poll++) {
            $instance = $provider->provider()->findServer($id);
            $log->write(LaunchPolls::checkLine($poll, $this->stateOf($instance)));

            if ($instance->isReachable()) {
                return $instance;
            }

            LaunchPolls::pause();
        }

        throw new RuntimeException('It did not start within '.LaunchPolls::limit().'.');
    }

    private function stateOf(ProviderServer $instance): string
    {
        $state = $instance->running ? 'running' : 'starting';
        $addresses = implode(', ', array_filter([
            $instance->publicIp === null ? null : "public IP {$instance->publicIp}",
            $instance->privateIp === null ? null : "private IP {$instance->privateIp}",
        ]));

        return $addresses === '' ? "{$state}, no IP address yet" : "{$state}, {$addresses}";
    }
}
