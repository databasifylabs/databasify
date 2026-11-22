<?php

declare(strict_types=1);

namespace App\Mcp\Tools\StorageProviders;

use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Resources\Api\V1\StorageProviderResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\StorageProvider;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_storage_provider')]
#[Description('Get one storage provider with the databases that back up to it.')]
#[IsReadOnly]
#[RequiresAccess(AccessArea::StorageProviders, AccessLevel::Read)]
class GetStorageProviderTool extends McpTool
{
    public function respond(StorageProvider $provider): Response
    {
        return $this->json(StorageProviderResource::loaded($provider));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'provider' => $this->uuid($schema, 'The storage provider id.')->required(),
        ];
    }
}
