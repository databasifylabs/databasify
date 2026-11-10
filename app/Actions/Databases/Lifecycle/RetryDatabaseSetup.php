<?php

declare(strict_types=1);

namespace App\Actions\Databases\Lifecycle;

use App\Actions\Databases\Flows\StartFlow;
use App\Engines\Flows\CreateDatabaseFlow;
use App\Engines\Flows\FlowContext;
use App\Models\Database;
use Illuminate\Validation\ValidationException;

final readonly class RetryDatabaseSetup
{
    public function __construct(
        private StartFlow $startFlow,
        private CreateDatabaseFlow $createFlow,
    ) {}

    public function handle(Database $database): void
    {
        $database->ensureIdleOnAnyWrapper();

        if (! $database->hasFailed()) {
            throw ValidationException::withMessages(['database' => "{$database->name} finished its setup, there is nothing to retry."]);
        }

        $database->markCreating();
        $this->startFlow->handle($database, $this->createFlow, new FlowContext(array_values(array_filter($database->nodes()->pluck('id')->all(), is_string(...)))));
    }
}
