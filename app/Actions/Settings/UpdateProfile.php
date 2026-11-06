<?php

declare(strict_types=1);

namespace App\Actions\Settings;

use App\DTOs\Settings\ProfileData;
use App\Models\User;

final readonly class UpdateProfile
{
    public function handle(User $user, ProfileData $data): void
    {
        $user->fill(['name' => $data->name, 'email' => $data->email]);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();
    }
}
