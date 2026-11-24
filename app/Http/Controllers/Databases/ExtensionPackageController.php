<?php

declare(strict_types=1);

namespace App\Http\Controllers\Databases;

use App\Actions\Databases\Extensions\AddExtensionPackage;
use App\Actions\Databases\Extensions\ReadPackageCandidate;
use App\Actions\Databases\Extensions\ReadPackagePlatform;
use App\Actions\Databases\Extensions\RemoveExtensionPackage;
use App\Actions\Databases\Extensions\UpdateExtensionPackage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Databases\StoreExtensionPackageRequest;
use App\Http\Resources\Api\V1\ExtensionPackageResource;
use App\Models\Database;
use App\Support\Databases\ExtensionChanges;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class ExtensionPackageController extends Controller
{
    public function index(Database $database, ExtensionChanges $changes, ReadPackagePlatform $platform): JsonResponse
    {
        return response()->json([
            'packages' => ExtensionPackageResource::collection($database->settings->packages),
            'change' => $changes->of($database)?->toArray(),
            'platform' => $platform->handle($database)->toArray(),
        ]);
    }

    public function store(StoreExtensionPackageRequest $request, Database $database, AddExtensionPackage $add): RedirectResponse
    {
        $add->handle($database, $request->toPackageRequest());

        return back();
    }

    public function candidate(Database $database, string $package, ReadPackageCandidate $read): JsonResponse
    {
        return response()->json($read->handle($database, $package)->toArray());
    }

    public function update(Database $database, string $package, UpdateExtensionPackage $update): RedirectResponse
    {
        $update->handle($database, $package);

        return back();
    }

    public function destroy(Database $database, string $package, RemoveExtensionPackage $remove): RedirectResponse
    {
        $remove->handle($database, $package);

        return back();
    }
}
