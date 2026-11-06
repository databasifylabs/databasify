<?php

declare(strict_types=1);

namespace App\Actions\Setup;

use App\DTOs\Setup\NewOwner;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class CreateOwnerAccount
{
    public function handle(NewOwner $owner): User
    {
        return DB::transaction(function () use ($owner): User {
            if (User::query()->exists()) {
                throw ValidationException::withMessages(['email' => 'Databasify is already set up. Sign in instead.']);
            }

            $user = User::query()->create($owner->toRow());
            $user->markEmailAsVerified();

            return $user;
        });
    }
}
