<?php

declare(strict_types=1);

namespace App\Support\Providers;

use App\Actions\Providers\AwaitServerSsh;
use App\Models\ServerProvider;

final readonly class ProviderLogNames
{
    public static function order(ServerProvider $provider): string
    {
        return "Order server from {$provider->label()}";
    }

    public static function await(ServerProvider $provider): string
    {
        return "Wait for {$provider->label()} to start the server";
    }

    /**
     * @return list<string>
     */
    public static function launch(ServerProvider $provider): array
    {
        return [self::order($provider), self::await($provider), AwaitServerSsh::LOG_NAME];
    }
}
