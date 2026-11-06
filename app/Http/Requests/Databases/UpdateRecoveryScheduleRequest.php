<?php

declare(strict_types=1);

namespace App\Http\Requests\Databases;

use App\Http\Requests\Concerns\RecoveryScheduleRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateRecoveryScheduleRequest extends FormRequest
{
    use RecoveryScheduleRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->scheduleRules();
    }
}
