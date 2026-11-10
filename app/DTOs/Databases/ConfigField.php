<?php

declare(strict_types=1);

namespace App\DTOs\Databases;

use App\Engines\Data\EngineField;
use App\Enums\Databases\ConfigApply;

final readonly class ConfigField
{
    private const string INTEGER_PATTERN = '-?[0-9]{1,20}';

    private const string DECIMAL_PATTERN = '-?[0-9]{1,12}(\\.[0-9]{1,6})?';

    /**
     * @param  list<string>  $options
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public string $pattern,
        public string $default,
        public array $options = [],
        public bool $multiple = false,
        public ?string $unit = null,
        public int|float|null $min = null,
        public int|float|null $max = null,
        public ConfigApply $apply = ConfigApply::Dynamic,
        public bool $tuned = false,
    ) {}

    public static function fromEngine(EngineField $field): self
    {
        $ranged = in_array($field->kind, ['integer', 'decimal'], true);

        return new self(
            key: $field->key,
            label: $field->label,
            description: $field->description,
            pattern: match ($field->kind) {
                'integer' => self::INTEGER_PATTERN,
                'decimal' => self::DECIMAL_PATTERN,
                'choice' => self::alternationOf($field->options),
                'flags' => '('.self::alternationOf($field->options).'(,'.self::alternationOf($field->options).')*)?',
                'text' => $field->pattern ?? '.*',
                default => '.+',
            },
            default: $field->default,
            options: $field->options,
            multiple: $field->kind === 'flags',
            unit: $field->unit,
            min: $ranged ? $field->min : null,
            max: $ranged ? $field->max : null,
            apply: $field->restart ? ConfigApply::Restart : ConfigApply::Dynamic,
            tuned: $field->tuned,
        );
    }

    public static function text(string $key, string $label, string $description, string $pattern, string $default): self
    {
        return new self($key, $label, $description, $pattern, $default);
    }

    public static function integer(string $key, string $label, string $description, int $min, int $max, string $default, ?string $unit = null): self
    {
        return new self($key, $label, $description, self::INTEGER_PATTERN, $default, unit: $unit, min: $min, max: $max);
    }

    public static function decimal(string $key, string $label, string $description, int|float $min, int|float $max, string $default, ?string $unit = null): self
    {
        return new self($key, $label, $description, self::DECIMAL_PATTERN, $default, unit: $unit, min: $min, max: $max);
    }

    /**
     * @param  list<string>  $options
     */
    public static function choice(string $key, string $label, string $description, array $options, string $default): self
    {
        return new self($key, $label, $description, self::alternationOf($options), $default, $options);
    }

    /**
     * @param  list<string>  $options
     */
    public static function flags(string $key, string $label, string $description, array $options, string $default): self
    {
        $option = self::alternationOf($options);

        return new self($key, $label, $description, "({$option}(,{$option})*)?", $default, $options, multiple: true);
    }

    public function restarting(): self
    {
        return clone ($this, ['apply' => ConfigApply::Restart]);
    }

    public function tunedFromServer(): self
    {
        return clone ($this, ['tuned' => true]);
    }

    public function needsRestart(): bool
    {
        return $this->apply === ConfigApply::Restart;
    }

    public function allows(string $value): bool
    {
        if (preg_match("/^{$this->pattern}$/", $value) !== 1) {
            return false;
        }

        return $this->withinRange($value);
    }

    /**
     * @return array{key: string, label: string, description: string, default: string, options: list<string>, multiple: bool, unit: ?string, min: int|float|null, max: int|float|null, apply: string, tuned: bool}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'description' => $this->description,
            'default' => $this->default,
            'options' => $this->options,
            'multiple' => $this->multiple,
            'unit' => $this->unit,
            'min' => $this->min,
            'max' => $this->max,
            'apply' => $this->apply->value,
            'tuned' => $this->tuned,
        ];
    }

    private function withinRange(string $value): bool
    {
        if ($this->min === null || $this->max === null) {
            return true;
        }

        return (float) $value >= $this->min && (float) $value <= $this->max;
    }

    /**
     * @param  list<string>  $options
     */
    private static function alternationOf(array $options): string
    {
        return '('.implode('|', array_map(fn (string $option): string => preg_quote($option, '/'), $options)).')';
    }
}
