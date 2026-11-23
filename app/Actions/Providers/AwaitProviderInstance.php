<?php

declare(strict_types=1);

namespace App\Actions\Providers;

use App\Models\Server;
use App\Models\ServerProvider;
use App\Support\Providers\LaunchPolls;
use App\Support\Providers\ProviderLogNames;
use RuntimeException;

final readonly class AwaitProviderInstance
{
    public function handle(Server $server, ServerProvider $provider, string $id): void
    {
        $log = $server->startLog(ProviderLogNames::await($provider));

        for ($poll = 1; $poll <= LaunchPolls::MAX; $poll++) {
            $instance = $provider->provider()->findServer($id);
            $addresses = implode(', ', array_filter([
                $instance->publicIp === null ? null : "public IP {$instance->publicIp}",
                $instance->privateIp === null ? null : "private IP {$instance->privateIp}",
            ]));
            $state = $instance->running ? 'running' : 'starting';
            $log->write(LaunchPolls::checkLine($poll, $addresses === '' ? "{$state}, no IP address yet" : "{$state}, {$addresses}"));

            if ($instance->isReachable()) {
                $server->recordLaunch($instance);
                $log->finish(0);

                return;
            }

            LaunchPolls::pause();
        }

        throw new RuntimeException('It did not start within '.LaunchPolls::limit().'.');
    }
}
