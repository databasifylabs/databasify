<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\Access\AccessLevel;
use App\Mcp\Attributes\RequiresAccess;
use App\Models\Project;
use App\Models\User;
use App\Queries\Query;
use App\Support\Projects\ProjectContext;
use Illuminate\Container\Container;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use LogicException;
use ReflectionClass;
use ReflectionParameter;

abstract class McpTool extends Tool
{
    public function __construct(
        protected readonly ProjectContext $context,
        private readonly Container $container,
        private readonly Router $router,
    ) {}

    public function shouldRegister(Request $request): bool
    {
        $user = $request->user();
        $attribute = (new ReflectionClass(static::class))->getAttributes(RequiresAccess::class)[0] ?? throw new LogicException(static::class.' has no #[RequiresAccess].');
        $access = $attribute->newInstance();

        if (! $user instanceof User) {
            return false;
        }

        return $user->tokenCan($access->area->writeScope())
            || ($access->level === AccessLevel::Read && $user->tokenCan($access->area->readScope()));
    }

    public function handle(Request $request): Response
    {
        $respond = [$this, 'respond'];

        if (! is_callable($respond)) {
            throw new LogicException(static::class.' has no respond() method.');
        }

        $httpRequest = HttpRequest::create('/mcp', 'POST', $request->all())->setUserResolver(fn (): mixed => $request->user());
        $this->context->terminate(keepAllowedProjects: true);

        if ($httpRequest->filled('project') && $this->context->initialize($httpRequest->string('project')->value()) === null) {
            throw $this->notFound(Project::class);
        }

        $models = $this->bindModels($httpRequest);

        if ($this->context->id() === null) {
            $this->context->initialize(collect($models)->whereInstanceOf(Model::class)->first());
        }

        $previous = $this->container->make('request');
        $this->container->instance('request', $httpRequest);

        try {
            $response = $this->container->call($respond, $models);
        } finally {
            $this->container->instance('request', $previous);
        }

        return $response instanceof Response ? $response : throw new LogicException(static::class.'::respond() must return a Response.');
    }

    protected function json(JsonResource $resource): Response
    {
        return Response::json($resource->resolve());
    }

    /**
     * @param  Query<covariant Model>  $query
     * @param  class-string<JsonResource>  $resource
     */
    protected function page(Query $query, string $resource): Response
    {
        $page = $query->cursorPaginate();

        return Response::json([
            'data' => $resource::collection($page->items())->resolve(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    protected function uuid(JsonSchema $schema, string $description): Type
    {
        return $schema->string()->format('uuid')->description($description);
    }

    protected function cursor(JsonSchema $schema): Type
    {
        return $schema->string()->description('The next_cursor from the previous page.');
    }

    /**
     * @return array<string, mixed>
     */
    private function bindModels(HttpRequest $request): array
    {
        $route = (new Route(['POST'], 'mcp', ['uses' => static::class.'@respond']))->bind($request)->scopeBindings();
        $request->setRouteResolver(fn (): Route => $route);
        $models = collect($route->signatureParameters(['subClass' => UrlRoutable::class]))->whereInstanceOf(ReflectionParameter::class);

        $models->each(function (ReflectionParameter $model) use ($request, $route): void {
            $argument = Str::snake($model->getName());

            if ($request->filled($argument) || $model->getType()?->allowsNull() !== true) {
                $route->setParameter($model->getName(), $request->string($argument)->value());
            }
        });

        try {
            $this->router->substituteImplicitBindings($route);
        } catch (ModelNotFoundException $exception) {
            throw $this->notFound($exception->getModel());
        }

        return $models->mapWithKeys(fn (ReflectionParameter $model): array => [$model->getName() => $route->parameter($model->getName())])->all();
    }

    private function notFound(string $model): ValidationException
    {
        $name = Str::snake(class_basename($model));

        return ValidationException::withMessages([$name => "No {$name} with this id."]);
    }
}
