<?php

declare(strict_types=1);

namespace App\Actions\Setup;

use App\DTOs\Setup\NewOwner;
use App\Models\Project;
use App\Models\User;
use App\Support\Setup\SetupCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class CreateOwnerAccount
{
    private const int WAIT_SECONDS = 30;

    public function __construct(private SetupCache $cache) {}

    public function handle(NewOwner $owner): User
    {
        $lock = $this->cache->ownerLock();
        $lock->block(self::WAIT_SECONDS);

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
