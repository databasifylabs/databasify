<?php

declare(strict_types=1);

namespace App\DTOs\Forms;

final readonly class DynamicForm
{
    /**
     * @param  list<DynamicField>  $fields
     */
    private function __construct(public array $fields) {}

    /**
     * @param  list<DynamicField>  $fields
     */
    public static function make(array $fields = []): self
    {
        return new self($fields);
    }

    /**
     * @param  array<mixed>  $fields
     */
    public static function fromArray(array $fields): self
    {
        $parsed = [];

        foreach ($fields as $field) {
            if (is_array($field)) {
                $parsed[] = DynamicField::fromArray($field);
            }
        }

        return new self($parsed);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(string $prefix, bool $required): array
    {
        $rules = [];

        foreach ($this->fields as $field) {
            $rules["{$prefix}.{$field->name}"] = $field->rules($required);
        }

        return $rules;
    }

    /**
     * @param  array<mixed>  $input
     * @param  array<string, string>  $current
     * @return array<string, string>
     */
    public function values(array $input, array $current = []): array
    {
        $values = [];

        foreach ($this->fields as $field) {
            $value = $input[$field->name] ?? null;
            $values[$field->name] = is_string($value) && trim($value) !== '' ? trim($value) : ($current[$field->name] ?? '');
        }

        return $values;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(fn (DynamicField $field): array => $field->toArray(), $this->fields);
    }
}
