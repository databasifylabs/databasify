<?php

declare(strict_types=1);

namespace App\Actions\Settings;

use App\Models\User;

final readonly class UpdatePassword
{
    public function handle(User $user, string $password): void
    {
        $user->update(['password' => $password]);
    }
}
