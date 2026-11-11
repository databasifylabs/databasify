<?php

declare(strict_types=1);

namespace App\Actions\Databases\Flows;

use App\Jobs\Databases\CleanUpDatabaseFlow;
use App\Models\DatabaseFlow;

final readonly class FailFlow
{
    public function handle(DatabaseFlow $flow, string $error): void
    {
        $flow->fail($error);

        dispatch(new CleanUpDatabaseFlow($flow));
    }
}
