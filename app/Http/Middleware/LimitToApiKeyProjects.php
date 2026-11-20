<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Project;
use App\Queries\ApiKeys\CurrentApiKeyQuery;
use App\Support\Projects\ProjectContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

final readonly class LimitToApiKeyProjects
{
    public function __construct(private ProjectContext $context, private CurrentApiKeyQuery $currentKey) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->context->limitTo($this->currentKey->for($request)?->allowedProjectIds());
        $route = $request->route();

        if ($route instanceof Route && $route->hasParameter('project')) {
            $this->enterProjectOf($route);
        }

        return $next($request);
    }

    public function terminate(): void
    {
        $this->context->terminate();
    }

    private function enterProjectOf(Route $route): void
    {
        $projectId = $route->originalParameter('project');

        abort_unless(is_string($projectId) && $this->context->allows($projectId), 404);

        $project = Project::query()->whereKey($projectId)->firstOrFail();
        $this->context->initialize($project);
        URL::defaults(['project' => $project->id]);
    }
}
