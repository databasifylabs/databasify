<?php

declare(strict_types=1);

namespace App\Http\Requests\Databases;

use App\Http\Requests\Concerns\ResolvesRouteModel;
use App\Support\Databases\SchemaName;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSchemaRequest extends FormRequest
{
    use ResolvesRouteModel;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:64', 'regex:'.SchemaName::PATTERN,
                Rule::notIn($this->routeDatabase()->facts()->reservedDatabases),
                Rule::unique('schemas', 'name')->where('database_id', $this->routeDatabase()->id),
            ],
        ];
    }

    public function schemaName(): string
    {
        return $this->string('name')->value();
    }
}
