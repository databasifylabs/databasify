<?php

declare(strict_types=1);

namespace App\Exceptions\Databases;

use App\Models\Server;
use RuntimeException;

final class ServerSizeUnknown extends RuntimeException
{
    public static function of(Server $server): self
    {
        return new self("{$server->name} has no known CPU, memory or disk size yet.");
    }
}
