<?php

declare(strict_types=1);

namespace App\Actions\Setup;

use App\DTOs\Setup\NewOwner;
use App\Models\Project;
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

            $project = Project::query()->firstOrCreate(['name' => 'Default']);
            $user = User::query()->create([...$owner->toRow(), 'last_project_id' => $project->id]);
            $user->markEmailAsVerified();

            return $user;
        });
    }
}
