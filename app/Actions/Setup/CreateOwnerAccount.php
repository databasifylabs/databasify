<?php

declare(strict_types=1);

namespace App\Actions\Setup;

use App\DTOs\Setup\NewOwner;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class CreateOwnerAccount
{
    private const int LOCK_SECONDS = 30;

    public function handle(NewOwner $owner): User
    {
        $lock = Cache::lock('setup:owner', self::LOCK_SECONDS);
        $lock->block(self::LOCK_SECONDS);

        try {
            return DB::transaction(function () use ($owner): User {
                if (User::query()->exists()) {
                    throw ValidationException::withMessages(['email' => 'Databasify is already set up. Sign in instead.']);
                }

                $project = Project::query()->firstOrCreate(['name' => 'Default']);
                $created = User::query()->create([...$owner->toRow(), 'last_project_id' => $project->id]);
                $created->markEmailAsVerified();

                return $created;
            });
        } finally {
            $lock->release();
        }
    }
}
