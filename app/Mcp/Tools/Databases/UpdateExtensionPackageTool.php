<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Databases;

use App\Actions\Databases\Extensions\UpdateExtensionPackage;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Resources\Api\V1\DatabaseResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\Database;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_extension_package')]
#[Description('Install the newest version of an official-repository or repository extension package on every node of a database. Its extensions stay enabled; update each schema afterwards with update_database_extension. It runs in the background; poll get_database until the status settles.')]
#[RequiresAccess(AccessArea::Databases, AccessLevel::Write)]
class UpdateExtensionPackageTool extends McpTool
{
    public function respond(Request $request, Database $database, UpdateExtensionPackage $update): Response
    {
        $update->handle($database, $request->string('package')->value());

        return $this->json(new DatabaseResource($database->refresh()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'database' => $this->uuid($schema, 'The database id.')->required(),
            'package' => $schema->string()->description('The package id from list_extension_packages.')->required(),
        ];
    }
}
