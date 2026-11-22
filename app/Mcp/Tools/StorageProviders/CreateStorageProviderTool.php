<?php

declare(strict_types=1);

namespace App\Mcp\Tools\StorageProviders;

use App\Actions\Storage\AddStorageProvider;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Requests\Storage\StoreStorageProviderRequest;
use App\Http\Resources\Api\V1\StorageProviderResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_storage_provider')]
#[Description('Add an S3-compatible storage provider to a project. The bucket is checked with a test write first.')]
#[RequiresAccess(AccessArea::StorageProviders, AccessLevel::Write)]
class CreateStorageProviderTool extends McpTool
{
    public function respond(StoreStorageProviderRequest $request, AddStorageProvider $add): Response
    {
        return $this->json(StorageProviderResource::loaded($add->handle($request->toProvider())));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->uuid($schema, 'The project to add it to.')->required(),
            'name' => $schema->string()->description('A unique name for the provider.')->required(),
            'endpoint' => $schema->string()->description('The https S3 endpoint.')->required(),
            'region' => $schema->string()->description('The bucket region, such as us-east-1.')->required(),
            'bucket' => $schema->string()->description('The bucket name.')->required(),
            'path_prefix' => $schema->string()->description('An optional folder inside the bucket.'),
            'path_style' => $schema->boolean()->description('Use path-style URLs (needed by most non-AWS services).'),
            'access_key_id' => $schema->string()->description('The access key id.')->required(),
            'secret_access_key' => $schema->string()->description('The secret access key.')->required(),
        ];
    }
}
