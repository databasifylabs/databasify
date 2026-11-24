<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Databases;

use App\Actions\Databases\Extensions\ReadPackageCandidate;
use App\Enums\Access\AccessArea;
use App\Enums\Access\AccessLevel;
use App\Mcp\Attributes\RequiresAccess;
use App\Mcp\Tools\McpTool;
use App\Models\Database;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('check_extension_package_version')]
#[Description('Show the installed and the newest available version of an official-repository or repository extension package of a database.')]
#[IsReadOnly]
#[RequiresAccess(AccessArea::Databases, AccessLevel::Read)]
class CheckExtensionPackageVersionTool extends McpTool
{
    public function respond(Request $request, Database $database, ReadPackageCandidate $read): Response
    {
        return Response::json($read->handle($database, $request->string('package')->value())->toArray());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'database' => $this->uuid($schema, 'The database id.')->required(),
            'package' => $schema->string()->description('The package id from list_extension_packages.')->required(),
        ];
    }
}
