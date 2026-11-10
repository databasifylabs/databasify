<?php

declare(strict_types=1);

namespace App\Actions\Databases\Flows;

use App\Enums\Databases\FlowState;
use App\Models\DatabaseFlow;
use Throwable;

final readonly class FailFlow
{
    public function handle(DatabaseFlow $flow, string $error): void
    {
        $flow->update(['state' => FlowState::Failed, 'error' => $error, 'operation_node_id' => null, 'operation_key' => null]);

        try {
            $flow->definition()->cleanup($flow);
        } catch (Throwable $exception) {
            report($exception);
        } finally {
            $flow->database->finishOperation();
        }
    }
}
