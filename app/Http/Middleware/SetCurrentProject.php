<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Project;
use App\Models\User;
use App\Support\Projects\ProjectContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\URL;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

final readonly class SetCurrentProject
{
    public function __construct(private ProjectContext $context) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $route = $this->routeOf($request);
        $project = Project::query()->whereKey($route->originalParameter('project'))->firstOrFail();

        $this->context->initialize($project);
        URL::defaults(['project' => $project->id]);
        $this->rememberFor($request->user(), $project);
        $route->forgetParameter('project');

        return $next($request);
    }

    public function terminate(): void
    {
        $this->context->terminate();
    }

    private function routeOf(Request $request): Route
    {
        $route = $request->route();

        return $route instanceof Route ? $route : throw new LogicException('SetCurrentProject runs on routes only.');
    }

    private function rememberFor(mixed $user, Project $project): void
    {
        if ($user instanceof User) {
            $user->rememberProject($project);
        }
    }
}
