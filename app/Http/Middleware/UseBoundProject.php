<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Project;
use App\Support\Projects\ProjectContext;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

final readonly class UseBoundProject
{
    public function __construct(private ProjectContext $context) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        if ($this->context->id() !== null || ! $route instanceof Route) {
            return $next($request);
        }

        $projectId = collect($route->parameters())
            ->map(fn (mixed $parameter): mixed => $parameter instanceof Model ? ($parameter->getAttributes()['project_id'] ?? null) : null)
            ->first(fn (mixed $id): bool => is_string($id));

        if (is_string($projectId)) {
            $this->context->initialize(Project::query()->findOrFail($projectId));
        }

        return $next($request);
    }
}
