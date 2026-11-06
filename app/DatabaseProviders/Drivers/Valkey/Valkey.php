<?php

declare(strict_types=1);

namespace App\DatabaseProviders\Drivers\Valkey;

use App\DatabaseProviders\Drivers\AbstractSentinelDriver;
use App\Enums\Databases\SentinelFlavor;

final readonly class Valkey extends AbstractSentinelDriver
{
    public static function id(): string
    {
        return 'valkey';
    }

    public function flavor(): SentinelFlavor
    {
        return SentinelFlavor::Valkey;
    }
}
