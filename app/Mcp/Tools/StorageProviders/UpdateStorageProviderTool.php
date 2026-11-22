<?php

declare(strict_types=1);

namespace App\Mcp\Tools\StorageProviders;

use App\Actions\Storage\UpdateStorageProvider;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Requests\Storage\UpdateStorageProviderRequest;
use App\Http\Resources\Api\V1\StorageProviderResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\StorageProvider;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_storage_provider')]
#[Description('Change a storage provider. Leave secret_access_key out to keep the current secret.')]
#[RequiresAccess(AccessArea::StorageProviders, AccessLevel::Write)]
class UpdateStorageProviderTool extends McpTool
{
    public function respond(UpdateStorageProviderRequest $request, StorageProvider $provider, UpdateStorageProvider $update): Response
    {
        return $this->json(StorageProviderResource::loaded($update->handle($provider, $request->toProvider($provider))));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'provider' => $this->uuid($schema, 'The storage provider id.')->required(),
            'name' => $schema->string()->description('A unique name for the provider.')->required(),
            'endpoint' => $schema->string()->description('The https S3 endpoint.')->required(),
            'region' => $schema->string()->description('The bucket region, such as us-east-1.')->required(),
            'bucket' => $schema->string()->description('The bucket name.')->required(),
            'path_prefix' => $schema->string()->description('An optional folder inside the bucket.'),
            'path_style' => $schema->boolean()->description('Use path-style URLs (needed by most non-AWS services).'),
            'access_key_id' => $schema->string()->description('The access key id.')->required(),
            'secret_access_key' => $schema->string()->description('A new secret access key.'),
        ];
    }
}
