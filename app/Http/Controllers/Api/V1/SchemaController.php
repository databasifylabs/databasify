<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Databases\Accounts\CreateSchema;
use App\Actions\Databases\Accounts\DeleteSchema;
use App\Http\Controllers\Controller;
use App\Http\Requests\Databases\StoreSchemaRequest;
use App\Http\Resources\Api\V1\SchemaResource;
use App\Models\Database;
use App\Models\Schema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class SchemaController extends Controller
{
    public function index(Database $database): AnonymousResourceCollection
    {
        return SchemaResource::collection($database->schemas);
    }

    public function store(StoreSchemaRequest $request, Database $database, CreateSchema $create): JsonResponse
    {
        return (new SchemaResource($create->handle($database, $request->schemaName())))->response()->setStatusCode(201);
    }

    public function destroy(Database $database, Schema $schema, DeleteSchema $delete): Response
    {
        $delete->handle($schema);

        return response()->noContent();
    }
}
