<?php

declare(strict_types=1);

namespace App\Mcp\Tools\System;

use App\DTOs\Api\SystemInfo;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Resources\Api\V1\SystemResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Queries\Plugins\PluginQuery;
use App\Queries\Updates\LatestReleaseQuery;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_system')]
#[Description('Get the Databasify version, whether an update is available, and the installed plugins.')]
#[IsReadOnly]
#[RequiresAccess(AccessArea::System, AccessLevel::Read)]
class GetSystemTool extends McpTool
{
    public function respond(LatestReleaseQuery $latestRelease): Response
    {
        return $this->json(new SystemResource(new SystemInfo(
            config()->string('app.version'),
            $latestRelease->get()?->release->version,
            $latestRelease->hasNewerRelease(),
            PluginQuery::all()->get(),
        )));
    }
}
