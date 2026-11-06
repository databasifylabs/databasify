<?php

declare(strict_types=1);

namespace App\Http\Requests\Databases;

use App\DTOs\Recovery\PointInTimeRestore;
use App\Http\Requests\Concerns\DatabaseValidationRules;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RestoreToPointInTimeRequest extends FormRequest
{
    use DatabaseValidationRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => $this->databaseNameRules(),
            'server_id' => array_merge(['required'], $this->serverIdRules()),
            'target' => ['required', 'date'],
        ];
    }

    public function toRestore(): PointInTimeRestore
    {
        return new PointInTimeRestore(
            name: $this->string('name')->value(),
            serverId: $this->string('server_id')->value(),
            target: CarbonImmutable::parse($this->string('target')->value())->utc()->startOfSecond(),
        );
    }
}
