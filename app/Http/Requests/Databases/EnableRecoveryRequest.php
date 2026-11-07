<?php

declare(strict_types=1);

namespace App\Http\Requests\Databases;

use App\DTOs\Recovery\RecoverySettings;
use App\Http\Requests\Concerns\RecoveryScheduleRules;
use App\Models\StorageProvider;
use App\Rules\ExistsInProject;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class EnableRecoveryRequest extends FormRequest
{
    use RecoveryScheduleRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return array_merge(
            ['storage_provider_id' => ['required', 'uuid', new ExistsInProject(StorageProvider::class)]],
            $this->scheduleRules(),
        );
    }

    public function toSettings(): RecoverySettings
    {
        return new RecoverySettings($this->string('storage_provider_id')->value(), $this->toSchedule());
    }
}
