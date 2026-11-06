<?php

declare(strict_types=1);

namespace App\Http\Controllers\Databases;

use App\Actions\Databases\Accounts\ChangePasswordEncryption;
use App\Actions\Databases\Accounts\CreateDatabaseUser;
use App\Actions\Databases\Accounts\DeleteDatabaseUser;
use App\Actions\Databases\Accounts\ResetDatabaseUserPassword;
use App\Http\Controllers\Controller;
use App\Http\Requests\Databases\StoreDatabaseUserRequest;
use App\Http\Requests\Databases\UpdatePasswordEncryptionRequest;
use App\Models\Database;
use App\Models\DatabaseUser;
use Illuminate\Http\RedirectResponse;

class DatabaseUserController extends Controller
{
    public function store(StoreDatabaseUserRequest $request, Database $database, CreateDatabaseUser $createUser): RedirectResponse
    {
        $createUser->handle($database, $request->userName(), $request->encryption());

        return back();
    }

    public function resetPassword(Database $database, DatabaseUser $user, ResetDatabaseUserPassword $resetPassword): RedirectResponse
    {
        $resetPassword->handle($user);

        return back();
    }

    public function updateEncryption(UpdatePasswordEncryptionRequest $request, Database $database, DatabaseUser $user, ChangePasswordEncryption $changeEncryption): RedirectResponse
    {
        $changeEncryption->handle($user, $request->encryption());

        return back();
    }

    public function destroy(Database $database, DatabaseUser $user, DeleteDatabaseUser $deleteUser): RedirectResponse
    {
        $deleteUser->handle($user);

        return back();
    }
}
