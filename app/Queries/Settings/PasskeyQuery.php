<?php

declare(strict_types=1);

namespace App\Queries\Settings;

use App\Models\Passkey;
use App\Models\User;
use App\Queries\Query;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Query<Passkey>
 */
final class PasskeyQuery extends Query
{
    private function __construct(private readonly User $user) {}

    public static function of(User $user): self
    {
        return new self($user);
    }

    public function builder(): Builder
    {
        return Passkey::query()->whereBelongsTo($this->user)->select(['id', 'name', 'credential', 'created_at', 'last_used_at'])->latest();
    }
}
