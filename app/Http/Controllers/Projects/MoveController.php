<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\MoveToProject;
use App\Contracts\Projects\Movable;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\MoveToProjectRequest;
use App\Models\Database;
use App\Models\DnsProvider;
use App\Models\Project;
use App\Models\Server;
use App\Models\StorageProvider;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class MoveController extends Controller
{
    public function store(MoveToProjectRequest $request, MoveToProject $move): RedirectResponse
    {
        $resource = $request->resource();
        $target = $request->target();

        $move->handle($resource, $target);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Moved to {$target->name}."]);

        return $this->pageOf($resource, $target);
    }

    private function pageOf(Movable $resource, Project $target): RedirectResponse
    {
        return match (true) {
            $resource instanceof Server => to_route('servers.show', ['project' => $target, 'server' => $resource]),
            $resource instanceof Database => to_route('databases.show', ['project' => $target, 'database' => $resource]),
            $resource instanceof DnsProvider => to_route('dns.index', ['project' => $target]),
            $resource instanceof StorageProvider => to_route('storage-providers.index', ['project' => $target]),
            default => to_route('providers.index', ['project' => $target]),
        };
    }
}
