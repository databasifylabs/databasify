<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\Models\DeleteChildren;
use App\Models\User;

final readonly class UserObserver
{
    public function __construct(private DeleteChildren $children) {}

    public function deleted(User $user): void
    {
        $this->children->handle($user->passkeys());
    }
}
