<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\DTOs\Recovery\RecoverySchedule;
use Illuminate\Contracts\Validation\ValidationRule;

trait RecoveryScheduleRules
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function scheduleRules(): array
    {
        return [
            'retention_days' => ['required', 'integer', 'between:1,'.RecoverySchedule::MAX_RETENTION_DAYS],
            'backup_hour' => ['required', 'integer', 'between:0,23'],
        ];
    }

    public function toSchedule(): RecoverySchedule
    {
        return new RecoverySchedule($this->integer('retention_days'), $this->integer('backup_hour'));
    }
}
