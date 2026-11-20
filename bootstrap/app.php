<?php

declare(strict_types=1);

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\LimitToApiKeyProjects;
use App\Http\Middleware\RecordApiKeyUse;
use App\Http\Middleware\RedirectToSetup;
use App\Http\Middleware\SetCurrentProject;
use App\Http\Middleware\UseBoundProject;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: [
            __DIR__.'/../routes/web.php',
            __DIR__.'/../routes/settings.php',
            __DIR__.'/../routes/plugins.php',
            __DIR__.'/../routes/updates.php',
            __DIR__.'/../routes/api-keys.php',
            __DIR__.'/../routes/servers.php',
            __DIR__.'/../routes/providers.php',
            __DIR__.'/../routes/dns.php',
            __DIR__.'/../routes/storage.php',
            __DIR__.'/../routes/databases.php',
        ],
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        $middleware->redirectUsersTo(fn (): string => route('home'));

        $middleware->alias(['project' => SetCurrentProject::class]);

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            RedirectToSetup::class,
        ]);

        $middleware->api(append: [LimitToApiKeyProjects::class, UseBoundProject::class, RecordApiKeyUse::class]);

        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: RedirectToSetup::class);
        $middleware->appendToPriorityList(after: AuthenticatesRequests::class, append: SetCurrentProject::class);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: LimitToApiKeyProjects::class);
        $middleware->appendToPriorityList(after: SubstituteBindings::class, append: UseBoundProject::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request): bool => $request->is('api/*') || $request->expectsJson());
    })->create();
