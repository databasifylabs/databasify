<?php

declare(strict_types=1);

use App\Http\Middleware\LimitToApiKeyProjects;
use App\Http\Middleware\RecordApiKeyUse;
use App\Mcp\Servers\DatabasifyServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController;

Mcp::oauthRoutes(registration: false);

Route::post('oauth/register', OAuthRegisterController::class)
    ->middleware('throttle:10,1')
    ->name('mcp.oauth.register');

Mcp::web('/mcp', DatabasifyServer::class)
    ->middleware(['auth:api', 'throttle:api', LimitToApiKeyProjects::class, RecordApiKeyUse::class]);
