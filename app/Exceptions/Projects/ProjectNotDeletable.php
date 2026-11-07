<?php

declare(strict_types=1);

namespace App\Exceptions\Projects;

use Illuminate\Validation\ValidationException;

final class ProjectNotDeletable extends ValidationException
{
    public static function stillInUse(string $name): self
    {
        return self::withMessages(['project' => "{$name} still has servers, databases, networks or providers. Move or delete them first."]);
    }

    public static function lastOne(): self
    {
        return self::withMessages(['project' => 'The last project cannot be deleted.']);
    }
}
