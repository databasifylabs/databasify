<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Databases;

use App\Actions\Databases\Extensions\ReadPackagePlatform;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Resources\Api\V1\ExtensionPackageResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\Database;
use App\Support\Databases\ExtensionChanges;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_extension_packages')]
#[Description('List the custom extension packages of a database, and the package change running now.')]
#[IsReadOnly]
#[RequiresAccess(AccessArea::Databases, AccessLevel::Read)]
class ListExtensionPackagesTool extends McpTool
{
    public function respond(Database $database, ExtensionChanges $changes, ReadPackagePlatform $platform): Response
    {
        return Response::json([
            'packages' => ExtensionPackageResource::collection($database->settings->packages)->resolve(),
            'change' => $changes->of($database)?->toArray(),
            'platform' => $platform->handle($database)->toArray(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'database' => $this->uuid($schema, 'The database id.')->required(),
        ];
    }
}
