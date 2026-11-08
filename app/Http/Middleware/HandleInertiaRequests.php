<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\DTOs\Projects\ProjectLink;
use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\CurrentProjectQuery;
use App\Queries\Projects\ProjectQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function __construct(private readonly CurrentProjectQuery $currentProject) {}

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'name' => config()->string('app.name'),
            'version' => config()->string('app.version'),
            'auth' => [
                'user' => $request->user(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'horizonUrl' => $request->user() !== null && Gate::allows('viewHorizon') ? route('horizon.index') : null,
            'currentProject' => fn (): ?array => $this->currentProjectFor($request->user()),
            'projects' => fn (): array => $this->projectsFor($request->user()),
        ]);
    }

    /**
     * @return array{id: string, name: string}|null
     */
    private function currentProjectFor(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        $project = $this->currentProject->for($user);

        return $project === null ? null : $this->linkTo($project);
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function projectsFor(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        return array_values(ProjectQuery::links()->get()->map($this->linkTo(...))->all());
    }

    /**
     * @return array{id: string, name: string}
     */
    private function linkTo(Project $project): array
    {
        return ProjectLink::fromModel($project)->toArray();
    }
}
