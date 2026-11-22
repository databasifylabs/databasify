<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Projects;

use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Resources\Api\V1\ProjectResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Queries\Projects\ProjectQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_projects')]
#[Description('List projects with how many servers, databases and providers each holds. Pages of 25; pass next_cursor for more.')]
#[IsReadOnly]
#[RequiresAccess(AccessArea::Projects, AccessLevel::Read)]
class ListProjectsTool extends McpTool
{
    public function respond(): Response
    {
        return $this->page(ProjectQuery::withUsage(), ProjectResource::class);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['cursor' => $this->cursor($schema)];
    }
}
