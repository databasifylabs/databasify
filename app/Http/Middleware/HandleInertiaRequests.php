<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Database;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'name' => config()->string('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'clusterMemoryMb' => Database::CLUSTER_MEMORY_MB,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'horizonUrl' => $request->user() !== null && Gate::allows('viewHorizon') ? route('horizon.index') : null,
        ]);
    }
}
