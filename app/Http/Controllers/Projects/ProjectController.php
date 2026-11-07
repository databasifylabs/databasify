<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\CreateProject;
use App\Actions\Projects\DeleteProject;
use App\Actions\Projects\RenameProject;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\StoreProjectRequest;
use App\Http\Requests\Projects\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Queries\Projects\ProjectQuery;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('projects/index', [
            'projects' => ProjectResource::collection(ProjectQuery::withUsage()->get())->resolve(),
        ]);
    }

    public function store(StoreProjectRequest $request, CreateProject $create): RedirectResponse
    {
        return to_route('dashboard', ['project' => $create->handle($request->toProject())]);
    }

    public function update(UpdateProjectRequest $request, Project $project, RenameProject $rename): RedirectResponse
    {
        $rename->handle($project, $request->projectName());

        return to_route('projects.index');
    }

    public function destroy(Project $project, DeleteProject $delete): RedirectResponse
    {
        $delete->handle($project);

        return to_route('projects.index');
    }
}
