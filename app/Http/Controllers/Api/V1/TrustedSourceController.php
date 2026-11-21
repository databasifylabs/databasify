<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Databases\Network\AddNodeTrustedSource;
use App\Actions\Databases\Network\AddTrustedSource;
use App\Actions\Databases\Network\RemoveNodeTrustedSource;
use App\Actions\Databases\Network\RemoveTrustedSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Databases\StoreTrustedSourceRequest;
use App\Http\Resources\Api\V1\DatabaseNodeResource;
use App\Http\Resources\Api\V1\DatabaseResource;
use App\Http\Resources\Api\V1\TrustedSourceResource;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Models\TrustedSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TrustedSourceController extends Controller
{
    public function index(Database $database): AnonymousResourceCollection
    {
        return TrustedSourceResource::collection($database->trustedSources);
    }

    public function store(StoreTrustedSourceRequest $request, Database $database, AddTrustedSource $add): JsonResponse
    {
        $add->handle($database, $request->toSource());

        return DatabaseResource::accepted($database);
    }

    public function destroy(Database $database, TrustedSource $trustedSource, RemoveTrustedSource $remove): JsonResponse
    {
        $remove->handle($trustedSource);

        return DatabaseResource::accepted($database);
    }

    public function nodeIndex(Database $database, DatabaseNode $node): AnonymousResourceCollection
    {
        return TrustedSourceResource::collection($node->trustedSources);
    }

    public function nodeStore(StoreTrustedSourceRequest $request, Database $database, DatabaseNode $node, AddNodeTrustedSource $add): JsonResponse
    {
        $add->handle($node, $request->toSource());

        return DatabaseNodeResource::accepted($node);
    }

    public function nodeDestroy(Database $database, DatabaseNode $node, TrustedSource $trustedSource, RemoveNodeTrustedSource $remove): JsonResponse
    {
        $remove->handle($node, $trustedSource);

        return DatabaseNodeResource::accepted($node);
    }
}
