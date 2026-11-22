<?php

declare(strict_types=1);

namespace App\Mcp\Tools\StorageProviders;

use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Resources\Api\V1\StorageProviderResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Queries\Storage\StorageProviderQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_storage_providers')]
#[Description('List S3-compatible storage providers used for backups, with the databases that back up to each. Secret keys are never returned. Pages of 25; pass next_cursor for more.')]
#[IsReadOnly]
#[RequiresAccess(AccessArea::StorageProviders, AccessLevel::Read)]
class ListStorageProvidersTool extends McpTool
{
    public function respond(): Response
    {
        return $this->page(StorageProviderQuery::withRecoveries(), StorageProviderResource::class);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->uuid($schema, 'Only list this project. Omit to list every project the key can reach.'),
            'cursor' => $this->cursor($schema),
        ];
    }
}
