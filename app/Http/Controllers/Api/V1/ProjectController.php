<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Projects\CreateProject;
use App\Actions\Projects\DeleteProject;
use App\Actions\Projects\RenameProject;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DeleteProjectRequest;
use App\Http\Requests\Api\V1\StoreProjectRequest;
use App\Http\Requests\Projects\UpdateProjectRequest;
use App\Http\Resources\Api\V1\ProjectResource;
use App\Models\Project;
use App\Queries\Projects\ProjectQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ProjectController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ProjectResource::collection(ProjectQuery::withUsage()->cursorPaginate());
    }

    public function store(StoreProjectRequest $request, CreateProject $create): JsonResponse
    {
        return (new ProjectResource($create->handle($request->toProject())))->response()->setStatusCode(201);
    }

    public function update(UpdateProjectRequest $request, Project $project, RenameProject $rename): ProjectResource
    {
        $rename->handle($project, $request->projectName());

        return new ProjectResource($project->refresh());
    }

    public function destroy(DeleteProjectRequest $request, Project $project, DeleteProject $delete): Response
    {
        $delete->handle($project);

        return response()->noContent();
    }
}
