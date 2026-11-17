<?php

declare(strict_types=1);

namespace App\Actions\Databases\Lifecycle;

use App\Enums\Databases\DatabaseStatus;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Services\Databases\EngineTasks;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final readonly class StopOperation
{
    public function __construct(private EngineTasks $tasks) {}

    public function handle(Database|DatabaseNode $scope): void
    {
        $label = $scope->operationLabel();
        $blocked = match ($scope->operationStatus()) {
            DatabaseStatus::Recovering => "{$label} is failing over or recovering, which cannot be stopped halfway.",
            DatabaseStatus::Migrating => "{$label} is migrating, use the migration's Stop or Finish instead.",
            DatabaseStatus::Deleting => "{$label} is being deleted, which cannot be stopped.",
            default => null,
        };

        if ($blocked !== null) {
            throw ValidationException::withMessages(['database' => $blocked]);
        }

        if ($scope instanceof DatabaseNode && $scope->isClaimedByDatabase()) {
            $database = $scope->database->name;

            throw ValidationException::withMessages(['database' => "{$label} is part of {$database}'s operation, stop it from {$database}."]);
        }

        $running = $scope instanceof DatabaseNode ? $scope->runsItsOwnTask() : $scope->runsEngineTask();

        if (! $running) {
            throw ValidationException::withMessages(['database' => "{$label} has no running engine task to stop."]);
        }

        try {
            $this->tasks->cancel($scope);
        } catch (RuntimeException $failure) {
            throw ValidationException::withMessages(['database' => "Could not stop the running task: {$failure->getMessage()}"]);
        }
    }
}
