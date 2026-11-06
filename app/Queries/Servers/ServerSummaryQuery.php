<?php

declare(strict_types=1);

namespace App\Queries\Servers;

use App\DTOs\Servers\ServerSummary;
use App\Models\Server;

final readonly class ServerSummaryQuery
{
    private function __construct() {}

    public static function current(): self
    {
        return new self;
    }

    public function get(): ServerSummary
    {
        return new ServerSummary(
            total: Server::query()->count(),
            inUse: Server::query()->has('databaseNodes')->count(),
            available: Server::query()->available()->count(),
        );
    }
}
