<?php

declare(strict_types=1);

namespace App\Http\Requests\Databases;

use App\DTOs\Databases\ConfigField;
use App\Http\Requests\Concerns\ResolvesRouteModel;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDatabaseConfigRequest extends FormRequest
{
    use ResolvesRouteModel;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $fields = $this->allowedFields();

        return array_merge(
            ['config' => ['present', 'array:'.implode(',', array_keys($fields))]],
            collect($fields)->mapWithKeys(fn (ConfigField $field, string $key): array => [
                "config.{$key}" => ['nullable', 'string', 'max:200', $this->allowedBy($field)],
            ])->all(),
        );
    }

    /**
     * @return array<string, string>
     */
    public function config(): array
    {
        $config = [];

        foreach (array_keys($this->allowedFields()) as $key) {
            $value = $this->input("config.{$key}");

            if (is_string($value) && $value !== '') {
                $config[$key] = $value;
            }
        }

        return $config;
    }

    private function allowedBy(ConfigField $field): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($field): void {
            if (is_string($value) && ! $field->allows($value)) {
                $fail($this->rangeMessageFor($field));
            }
        };
    }

    private function rangeMessageFor(ConfigField $field): string
    {
        if ($field->min === null || $field->max === null) {
            return "{$field->label} has an invalid value.";
        }

        return "{$field->label} must be between {$field->min} and {$field->max}.";
    }

    /**
     * @return array<string, ConfigField>
     */
    private function allowedFields(): array
    {
        return collect($this->routeDatabase()->configFields())
            ->keyBy(fn (ConfigField $field): string => $field->key)
            ->all();
    }
}
