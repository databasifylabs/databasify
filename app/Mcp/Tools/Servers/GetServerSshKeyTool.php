<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Servers;

use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Services\Ssh\AppSshKey;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_server_ssh_key')]
#[Description('Get Databasify\'s public SSH key, to add to a server\'s authorized_keys before adding the server.')]
#[IsReadOnly]
#[RequiresAccess(AccessArea::Servers, AccessLevel::Read)]
class GetServerSshKeyTool extends McpTool
{
    public function respond(AppSshKey $appKey): Response
    {
        return Response::json(['public_key' => $appKey->get()->public_key]);
    }
}
