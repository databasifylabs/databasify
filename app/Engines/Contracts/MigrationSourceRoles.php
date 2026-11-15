<?php

declare(strict_types=1);

namespace App\Engines\Contracts;

interface MigrationSourceRoles
{
    public function grantMigrationSourceRoles(string $name): void;
}
