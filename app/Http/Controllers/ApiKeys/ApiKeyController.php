<?php

declare(strict_types=1);

namespace App\Http\Controllers\ApiKeys;

use App\Actions\Api\CreateApiKey;
use App\Actions\Api\RevokeApiKey;
use App\Enums\Access\AccessArea;
use App\Http\Controllers\Controller;
use App\Http\Requests\ApiKeys\StoreApiKeyRequest;
use App\Http\Resources\ApiKeyResource;
use App\Models\User;
use App\Queries\ApiKeys\ApiKeyQuery;
use App\Queries\Projects\ProjectQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ApiKeyController extends Controller
{
    public function index(#[CurrentUser] User $user): Response
    {
        return Inertia::render('api-keys/index', [
            'apiKeys' => ApiKeyResource::collection(ApiKeyQuery::of($user)->get())->resolve(),
            'areas' => AccessArea::options(),
            'projects' => ProjectQuery::links()->get()->map->only(['id', 'name'])->all(),
        ]);
    }

    public function store(StoreApiKeyRequest $request, #[CurrentUser] User $user, CreateApiKey $create): RedirectResponse
    {
        $created = $create->handle($user, $request->toNewApiKey());
        Inertia::flash('apiKey', ['name' => $created->key->name, 'key' => $created->plainTextKey]);

        return to_route('api-keys.index');
    }

    public function destroy(string $apiKey, #[CurrentUser] User $user, RevokeApiKey $revoke): RedirectResponse
    {
        $revoke->handle(ApiKeyQuery::of($user)->builder()->findOrFail($apiKey));

        return to_route('api-keys.index');
    }
}
