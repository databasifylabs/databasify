<?php

declare(strict_types=1);

namespace App\Http\Controllers\Plugins;

use App\Actions\Plugins\InstallPlugin;
use App\Actions\Plugins\SetPluginEnabled;
use App\Actions\Plugins\UninstallPlugin;
use App\Actions\Plugins\UpdatePlugin;
use App\Http\Controllers\Controller;
use App\Http\Requests\Plugins\InstallPluginRequest;
use App\Http\Requests\Plugins\UpdatePluginRequest;
use App\Http\Resources\PluginResource;
use App\Models\Plugin;
use App\Queries\Plugins\PluginQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PluginController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('plugins/index', [
            'plugins' => PluginResource::collection(PluginQuery::all()->get())->resolve(),
        ]);
    }

    public function store(InstallPluginRequest $request, InstallPlugin $install): RedirectResponse
    {
        $install->handle($request->toPluginSource());

        return back();
    }

    public function update(Request $request, Plugin $plugin, SetPluginEnabled $enable): RedirectResponse
    {
        $enable->handle($plugin, $request->boolean('enabled'));

        return back();
    }

    public function upgrade(UpdatePluginRequest $request, Plugin $plugin, UpdatePlugin $update): RedirectResponse
    {
        $update->handle($plugin, $request->toPluginSource($plugin));

        return back();
    }

    public function destroy(Plugin $plugin, UninstallPlugin $uninstall): RedirectResponse
    {
        $uninstall->handle($plugin);

        return back();
    }

    public function asset(Plugin $plugin, string $path): BinaryFileResponse
    {
        $file = $plugin->assetPath($path);

        abort_if($file === null, 404);

        return response()->file($file, ['Cache-Control' => 'public, max-age=3600']);
    }
}
