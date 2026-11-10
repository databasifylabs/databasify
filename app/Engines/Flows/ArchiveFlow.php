<?php

declare(strict_types=1);

namespace App\Engines\Flows;

use App\Actions\Databases\Recovery\RefreshRecoveryStatus;
use App\Contracts\Engines\Flow;
use App\Models\Database;
use App\Models\DatabaseFlow;

final readonly class ArchiveFlow implements Flow
{
    public function __construct(
        private FlowSteps $steps,
        private ApplySettingsFlow $settings,
        private RefreshRecoveryStatus $refresh,
    ) {}

    public function name(Database $database, FlowContext $context): string
    {
        return $database->pointInTimeRecovery === null ? 'Turn off point-in-time recovery' : 'Update point-in-time recovery';
    }

    public function steps(DatabaseFlow $flow): array
    {
        return [
            ...$this->settings->steps($flow),
            Step::finish('take a first backup', $this->steps->quick(fn () => $this->backUp($flow->database))),
        ];
    }

    public function cleanup(DatabaseFlow $flow): void
    {
        $this->settings->cleanup($flow);
    }

    private function backUp(Database $database): void
    {
        if ($database->pointInTimeRecovery === null) {
            return;
        }

        $this->refresh->handle($database->pointInTimeRecovery, backUpNow: true);
    }
}
