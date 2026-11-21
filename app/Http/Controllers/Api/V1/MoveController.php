<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Projects\MoveToProject;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\MoveToProjectRequest;
use App\Http\Resources\Api\V1\MovedResource;
use Illuminate\Database\Eloquent\Model;

class MoveController extends Controller
{
    public function __invoke(MoveToProjectRequest $request, MoveToProject $move): MovedResource
    {
        $resource = $request->resource();
        $move->handle($resource, $request->target());

        return new MovedResource($resource instanceof Model ? $resource->refresh() : $resource);
    }
}
