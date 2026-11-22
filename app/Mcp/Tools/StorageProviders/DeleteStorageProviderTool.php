<?php

declare(strict_types=1);

namespace App\Mcp\Tools\StorageProviders;

use App\Actions\Storage\DeleteStorageProvider;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\StorageProvider;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('delete_storage_provider')]
#[Description('Delete a storage provider. Refused while a database backs up to it.')]
#[IsDestructive]
#[RequiresAccess(AccessArea::StorageProviders, AccessLevel::Write)]
class DeleteStorageProviderTool extends McpTool
{
    public function respond(StorageProvider $provider, DeleteStorageProvider $delete): Response
    {
        $delete->handle($provider);

        return Response::json(['deleted' => $provider->id]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'provider' => $this->uuid($schema, 'The storage provider id.')->required(),
        ];
    }
}
