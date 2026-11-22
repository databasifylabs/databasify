<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Databases;

use App\DTOs\Databases\DatabaseFilters;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Enums\Databases\DatabaseStatus;
use App\Http\Resources\Api\V1\DatabaseResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\Database;
use App\Queries\Databases\DatabaseQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_databases')]
#[Description('List databases with their engine, version, status, health, nodes and hostnames. Filter by search text, status or engine. Pages of 25; pass next_cursor for more.')]
#[IsReadOnly]
#[RequiresAccess(AccessArea::Databases, AccessLevel::Read)]
class ListDatabasesTool extends McpTool
{
    public function respond(Request $request): Response
    {
        return $this->page(DatabaseQuery::forList(DatabaseFilters::fromRequest($request)), DatabaseResource::class);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->uuid($schema, 'Only list this project. Omit to list every project the key can reach.'),
            'search' => $schema->string()->description('Match part of the database name.'),
            'status' => $schema->string()->enum(array_column(DatabaseStatus::cases(), 'value'))->description('Only databases in this status.'),
            'engine' => $schema->string()->enum(Database::engines())->description('Only databases of this engine.'),
            'cursor' => $this->cursor($schema),
        ];
    }
}
