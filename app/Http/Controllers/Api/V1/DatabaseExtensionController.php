<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Databases\Extensions\DisableSchemaExtension;
use App\Actions\Databases\Extensions\EnableSchemaExtension;
use App\Actions\Databases\Extensions\ReadAvailableExtensions;
use App\Actions\Databases\Extensions\ReadSchemaExtensions;
use App\Actions\Databases\Extensions\UpdateSchemaExtension;
use App\Http\Controllers\Controller;
use App\Http\Requests\Databases\SchemaExtensionRequest;
use App\Http\Resources\Api\V1\AvailableExtensionResource;
use App\Http\Resources\Api\V1\DatabaseResource;
use App\Http\Resources\Api\V1\InstalledExtensionResource;
use App\Models\Database;
use App\Models\Schema;
use App\Support\Databases\ExtensionChanges;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DatabaseExtensionController extends Controller
{
    public function available(Database $database, ReadAvailableExtensions $readAvailable): AnonymousResourceCollection
    {
        return AvailableExtensionResource::collection($readAvailable->handle($database));
    }

    public function index(Database $database, Schema $schema, ReadSchemaExtensions $readInstalled, ExtensionChanges $changes): AnonymousResourceCollection
    {
        return InstalledExtensionResource::collection($readInstalled->handle($schema))->additional(['change' => $changes->of($database)?->toArray()]);
    }

    public function store(SchemaExtensionRequest $request, Database $database, Schema $schema, EnableSchemaExtension $enable): JsonResponse
    {
        $enable->handle($schema, $request->extensionName());

        return DatabaseResource::accepted($database);
    }

    public function update(Database $database, Schema $schema, string $extension, UpdateSchemaExtension $update): JsonResponse
    {
        $update->handle($schema, $extension);

        return DatabaseResource::accepted($database);
    }

    public function destroy(Database $database, Schema $schema, string $extension, DisableSchemaExtension $disable): JsonResponse
    {
        $disable->handle($schema, $extension);

        return DatabaseResource::accepted($database);
    }
}
