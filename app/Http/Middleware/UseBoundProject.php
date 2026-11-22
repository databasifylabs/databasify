<?php

declare(strict_types=1);

namespace App\Http\Middleware;

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

        $this->context->initialize(collect($route->parameters())
            ->whereInstanceOf(Model::class)
            ->first(fn (Model $parameter): bool => is_string($parameter->getAttributes()['project_id'] ?? null)));

        return $next($request);
    }
}
