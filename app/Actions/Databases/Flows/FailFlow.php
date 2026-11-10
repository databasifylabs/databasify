<?php

declare(strict_types=1);

namespace App\Actions\Databases\Flows;

use App\Enums\Databases\FlowState;
use App\Jobs\Databases\CleanUpDatabaseFlow;
use App\Models\DatabaseFlow;

final readonly class FailFlow
{
    public function handle(DatabaseFlow $flow, string $error): void
    {
        $flow->update(['state' => FlowState::Failed, 'error' => $error, 'operation_node_id' => null, 'operation_key' => null]);

        dispatch(new CleanUpDatabaseFlow($flow));
    }
}
