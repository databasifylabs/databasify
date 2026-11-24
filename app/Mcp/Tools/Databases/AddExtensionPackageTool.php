<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Databases;

use App\Actions\Databases\Extensions\AddExtensionPackage;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Http\Requests\Databases\StoreExtensionPackageRequest;
use App\Http\Resources\Api\V1\DatabaseResource;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\Database;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('add_extension_package')]
#[Description('Add a custom extension package to a database from the official repository of its engine or another apt repository. Package code runs with full database rights. It runs in the background; poll get_database until the status settles.')]
#[RequiresAccess(AccessArea::Databases, AccessLevel::Write)]
class AddExtensionPackageTool extends McpTool
{
    public function respond(Request $request, Database $database, AddExtensionPackage $add): Response
    {
        if ($request->input('source') === 'upload') {
            throw ValidationException::withMessages(['source' => 'Upload package files from the app or the REST API.']);
        }

        $add->handle($database, resolve(StoreExtensionPackageRequest::class)->toPackageRequest());

        return $this->json(new DatabaseResource($database->refresh()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'database' => $this->uuid($schema, 'The database id.')->required(),
            'source' => $schema->string()->enum(['official', 'repository'])->description('Where the package comes from.')->required(),
            'package' => $schema->string()->description('The package name, such as postgresql-18-http for PostgreSQL 18.')->required(),
            'repository' => $schema->string()->description('For repository: the repository line, such as "deb https://example.com/debian trixie main" for an apt repository.'),
            'repository_key' => $schema->string()->description('For repository: an https URL of the signing key, or the ASCII-armored key.'),
        ];
    }
}
