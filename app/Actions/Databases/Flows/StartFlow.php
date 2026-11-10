<?php

declare(strict_types=1);

namespace App\Actions\Databases\Flows;

use App\Contracts\Engines\Flow;
use App\Engines\Flows\FlowContext;
use App\Enums\Databases\FlowState;
use App\Jobs\Databases\AdvanceDatabaseFlow;
use App\Models\Database;
use App\Models\DatabaseFlow;

final readonly class StartFlow
{
    public function handle(Database $database, Flow $flow, FlowContext $context): DatabaseFlow
    {
        $database->startOperation($flow->name($database));

        $record = $database->flows()->create(['flow' => $flow::class, 'context' => $context, 'state' => FlowState::Running]);

        dispatch(new AdvanceDatabaseFlow($record));

        return $record;
    }
}
