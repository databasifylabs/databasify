<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Databases\Accounts\ChangePasswordEncryption;
use App\Actions\Databases\Accounts\CreateDatabaseUser;
use App\Actions\Databases\Accounts\DeleteDatabaseUser;
use App\Actions\Databases\Accounts\ResetDatabaseUserPassword;
use App\Http\Controllers\Controller;
use App\Http\Requests\Databases\StoreDatabaseUserRequest;
use App\Http\Requests\Databases\UpdatePasswordEncryptionRequest;
use App\Http\Resources\Api\V1\DatabaseUserCredentialsResource;
use App\Http\Resources\Api\V1\DatabaseUserResource;
use App\Models\Database;
use App\Models\DatabaseUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class DatabaseUserController extends Controller
{
    public function index(Database $database): AnonymousResourceCollection
    {
        return DatabaseUserResource::collection($database->users);
    }

    public function store(StoreDatabaseUserRequest $request, Database $database, CreateDatabaseUser $create): JsonResponse
    {
        return (new DatabaseUserCredentialsResource($create->handle($database, $request->userName(), $request->encryption())))->response()->setStatusCode(201);
    }

    public function credentials(Database $database, DatabaseUser $user): DatabaseUserCredentialsResource
    {
        return new DatabaseUserCredentialsResource($user);
    }

    public function resetPassword(Database $database, DatabaseUser $user, ResetDatabaseUserPassword $reset): DatabaseUserCredentialsResource
    {
        return new DatabaseUserCredentialsResource($reset->handle($user));
    }

    public function updateEncryption(UpdatePasswordEncryptionRequest $request, Database $database, DatabaseUser $user, ChangePasswordEncryption $change): DatabaseUserResource
    {
        return new DatabaseUserResource($change->handle($user, $request->encryption()));
    }

    public function destroy(Database $database, DatabaseUser $user, DeleteDatabaseUser $delete): Response
    {
        $delete->handle($user);

        return response()->noContent();
    }
}
