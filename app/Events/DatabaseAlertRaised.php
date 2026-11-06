<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Database;
use Illuminate\Foundation\Events\Dispatchable;

final class DatabaseAlertRaised
{
    use Dispatchable;

    public function __construct(
        public Database $database,
        public string $subject,
        public string $message,
    ) {}
}
