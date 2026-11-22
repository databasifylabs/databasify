<?php

declare(strict_types=1);

namespace App\Mcp\Tools\StorageProviders;

use App\Actions\Storage\TestStorageProvider;
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

#[Name('test_storage_provider')]
#[Description('Check a storage provider with a test write, read and delete, and mark it verified.')]
#[RequiresAccess(AccessArea::StorageProviders, AccessLevel::Write)]
class TestStorageProviderTool extends McpTool
{
    public function respond(StorageProvider $provider, TestStorageProvider $test): Response
    {
        $test->handle($provider);

        return $this->json(StorageProviderResource::loaded($provider));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'provider' => $this->uuid($schema, 'The storage provider id.')->required(),
        ];
    }
}
