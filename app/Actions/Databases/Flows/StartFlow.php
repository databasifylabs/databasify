<?php

declare(strict_types=1);

namespace App\Actions\Databases\Flows;

use App\Contracts\Engines\Flow;
use App\Engines\Flows\FlowContext;
use App\Enums\Databases\FlowState;
use App\Jobs\Databases\AdvanceDatabaseFlow;
use App\Models\Database;
use App\Models\DatabaseFlow;
use Closure;
use Illuminate\Support\Facades\DB;

final readonly class StartFlow
{
    private const string CLAIM = 'Starting';

    /**
     * @param  FlowContext|Closure(): FlowContext  $context
     */
    public function handle(Database $database, Flow $flow, FlowContext|Closure $context): DatabaseFlow
    {
        $record = DB::transaction(function () use ($database, $flow, $context): DatabaseFlow {
            $database->startOperation(self::CLAIM);
            $resolved = $context instanceof FlowContext ? $context : $context();
            $database->retitleOperation($flow->name($database, $resolved));

            return $database->flows()->create(['flow' => $flow::class, 'context' => $resolved, 'state' => FlowState::Running]);
        });

        dispatch(new AdvanceDatabaseFlow($record));

        return $record;
    }
}
