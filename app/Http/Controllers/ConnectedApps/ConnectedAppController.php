<?php

declare(strict_types=1);

namespace App\Http\Controllers\ConnectedApps;

use App\Actions\Api\RemoveConnectedApp;
use App\Enums\Access\AccessArea;
use App\Enums\Mcp\AiAgent;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConnectedAppResource;
use App\Models\User;
use App\Queries\ConnectedApps\ConnectedAppQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ConnectedAppController extends Controller
{
    public function index(#[CurrentUser] User $user): Response
    {
        return Inertia::render('connected-apps/index', [
            'connectedApps' => ConnectedAppResource::collection(ConnectedAppQuery::of($user)->get())->resolve(),
            'agents' => AiAgent::options(),
            'areas' => AccessArea::options(),
            'mcpUrl' => url('/mcp'),
        ]);
    }

    public function destroy(string $connectedApp, #[CurrentUser] User $user, RemoveConnectedApp $remove): RedirectResponse
    {
        $remove->handle(ConnectedAppQuery::of($user)->builder()->findOrFail($connectedApp));

        return to_route('connected-apps.index');
    }
}
