<?php

declare(strict_types=1);

namespace App\Queries\Servers;

use App\Models\Server;
use App\Models\ServerUser;
use App\Queries\Query;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Query<ServerUser>
 */
final class ServerUserQuery extends Query
{
    private function __construct(private readonly Server $server) {}

    public static function of(Server $server): self
    {
        return new self($server);
    }

    public function builder(): Builder
    {
        return $this->server->users()->getQuery()->orderBy('username')->select(['id', 'username']);
    }
}
