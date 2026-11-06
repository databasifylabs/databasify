<?php

declare(strict_types=1);

namespace App\DTOs\Forms;

final class DynamicField
{
    private string $type = 'text';

    private string $label;

    private ?string $description = null;

    private ?string $placeholder = null;

    private ?string $default = null;

    /** @var array<string, string> */
    private array $options = [];

    private bool $optional = false;

    private function __construct(public readonly string $name)
    {
        $this->label = ucfirst(str_replace('_', ' ', $name));
    }

    public static function make(string $name): self
    {
        return new self($name);
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $field = new self(is_string($data['name'] ?? null) ? $data['name'] : '');
        $field->type = is_string($data['type'] ?? null) ? $data['type'] : 'text';
        $field->label = is_string($data['label'] ?? null) ? $data['label'] : $field->label;
        $field->description = is_string($data['description'] ?? null) ? $data['description'] : null;
        $field->placeholder = is_string($data['placeholder'] ?? null) ? $data['placeholder'] : null;
        $field->default = is_string($data['default'] ?? null) ? $data['default'] : null;
        $field->optional = ($data['optional'] ?? false) === true;

        foreach (is_array($data['options'] ?? null) ? $data['options'] : [] as $option) {
            if (is_array($option) && is_string($option['value'] ?? null) && is_string($option['label'] ?? null)) {
                $field->options[$option['value']] = $option['label'];
            }
        }

        return $field;
    }

    public function text(): self
    {
        return $this->type('text');
    }

    public function password(): self
    {
        return $this->type('password');
    }

    public function textarea(): self
    {
        return $this->type('textarea');
    }

    /**
     * @param  array<string, string>  $options
     */
    public function select(array $options): self
    {
        $this->options = $options;

        return $this->type('select');
    }

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function placeholder(string $placeholder): self
    {
        $this->placeholder = $placeholder;

        return $this;
    }

    public function default(string $value): self
    {
        $this->default = $value;

        return $this;
    }

    public function optional(): self
    {
        $this->optional = true;

        return $this;
    }

    /**
     * @return list<mixed>
     */
    public function rules(bool $required): array
    {
        return array_merge([
            $required && ! $this->optional ? 'required' : 'nullable',
            'string',
            $this->type === 'textarea' ? 'max:10000' : 'max:500',
        ], $this->optionRules());
    }

    /**
     * @return list<string>
     */
    private function optionRules(): array
    {
        return $this->options === [] ? [] : ['in:'.implode(',', array_keys($this->options))];
    }

    /**
     * @return array{name: string, type: string, label: string, description: ?string, placeholder: ?string, default: ?string, options: list<array{value: string, label: string}>, optional: bool}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'label' => $this->label,
            'description' => $this->description,
            'placeholder' => $this->placeholder,
            'default' => $this->default,
            'options' => array_map(fn (string $value, string $label): array => ['value' => $value, 'label' => $label], array_keys($this->options), $this->options),
            'optional' => $this->optional,
        ];
    }

    private function type(string $type): self
    {
        $this->type = $type;

        return $this;
    }
}
