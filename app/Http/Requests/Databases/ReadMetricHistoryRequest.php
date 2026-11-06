<?php

declare(strict_types=1);

namespace App\Http\Requests\Databases;

use App\Enums\Databases\MetricPeriod;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReadMetricHistoryRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'period' => ['sometimes', Rule::enum(MetricPeriod::class)],
        ];
    }

    public function period(): MetricPeriod
    {
        return $this->enum('period', MetricPeriod::class) ?? MetricPeriod::Hour;
    }
}
