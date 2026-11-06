<?php

declare(strict_types=1);

namespace App\Actions\Databases\Failover;

use App\Models\Database;

final readonly class SwitchPrimary
{
    public function __construct(private PromoteStandby $promote) {}

    public function handle(Database $database): void
    {
        $this->promote->handle($database);
    }
}
