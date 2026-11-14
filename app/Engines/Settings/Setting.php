<?php

declare(strict_types=1);

namespace App\Engines\Settings;

use App\Engines\Data\EngineField;

final readonly class Setting
{
    public const string INTEGER = 'integer';

    public const string DECIMAL = 'decimal';

    public const string SIZE = 'size';

    public const string BYTES = 'bytes';

    public const string CHOICE = 'choice';

    public const string FLAGS = 'flags';

    public const string TEXT = 'text';

    public static function integer(string $key, string $label, string $description, float $min, float $max, string $default, bool $restart = false, bool $tuned = false, ?string $unit = null): EngineField
    {
        return new EngineField($key, $label, $description, self::INTEGER, $default, [], $unit, $min, $max, null, $restart, $tuned);
    }

    public static function decimal(string $key, string $label, string $description, float $min, float $max, string $default, bool $restart = false, bool $tuned = false, ?string $unit = null): EngineField
    {
        return new EngineField($key, $label, $description, self::DECIMAL, $default, [], $unit, $min, $max, null, $restart, $tuned);
    }

    public static function size(string $key, string $label, string $description, float $minKb, float $maxKb, string $default, bool $restart = false, bool $tuned = false): EngineField
    {
        return new EngineField($key, $label, $description, self::SIZE, $default, [], 'kB', $minKb, $maxKb, null, $restart, $tuned);
    }

    public static function bytes(string $key, string $label, string $description, float $min, float $max, string $default, bool $restart = false, bool $tuned = false): EngineField
    {
        return new EngineField($key, $label, $description, self::BYTES, $default, [], 'B', $min, $max, null, $restart, $tuned);
    }

    /**
     * @param  list<string>  $options
     */
    public static function choice(string $key, string $label, string $description, array $options, string $default, bool $restart = false, bool $tuned = false, ?string $unit = null): EngineField
    {
        return new EngineField($key, $label, $description, self::CHOICE, $default, $options, $unit, null, null, null, $restart, $tuned);
    }

    /**
     * @param  list<string>  $options
     */
    public static function flags(string $key, string $label, string $description, array $options, string $default, bool $restart = false, bool $tuned = false): EngineField
    {
        return new EngineField($key, $label, $description, self::FLAGS, $default, $options, null, null, null, null, $restart, $tuned);
    }

    public static function text(string $key, string $label, string $description, string $pattern, string $default, bool $restart = false, bool $tuned = false, ?string $unit = null): EngineField
    {
        return new EngineField($key, $label, $description, self::TEXT, $default, [], $unit, null, null, $pattern, $restart, $tuned);
    }

    public static function allows(EngineField $field, string $value): bool
    {
        return match ($field->kind) {
            self::INTEGER => preg_match('/^[+-]?\d+$/D', $value) === 1 && self::within($field, (float) $value),
            self::DECIMAL => is_numeric($value) && self::within($field, (float) $value),
            self::SIZE => self::sizeAllowed($field, $value),
            self::BYTES => self::bytesAllowed($field, $value),
            self::CHOICE => in_array($value, $field->options, true),
            self::FLAGS => $value === '' || array_diff(explode(',', $value), $field->options) === [],
            default => $field->pattern !== null && preg_match('/^(?:'.str_replace('/', '\/', $field->pattern).')$/D', $value) === 1,
        };
    }

    public static function bytesOf(string $value): ?float
    {
        if (preg_match('/^([0-9]{1,20})([KMGkmg]?)$/D', $value, $match) !== 1) {
            return null;
        }

        return (float) $match[1] * ['' => 1, 'k' => 1024, 'm' => 1024 ** 2, 'g' => 1024 ** 3][strtolower($match[2])];
    }

    private static function sizeAllowed(EngineField $field, string $value): bool
    {
        if (preg_match('/^([0-9]{1,15})(kB|MB|GB|TB)$/D', $value, $match) !== 1) {
            return false;
        }

        return self::within($field, (float) $match[1] * ['kB' => 1, 'MB' => 1024, 'GB' => 1024 ** 2, 'TB' => 1024 ** 3][$match[2]]);
    }

    private static function bytesAllowed(EngineField $field, string $value): bool
    {
        $bytes = self::bytesOf($value);

        return $bytes !== null && self::within($field, $bytes);
    }

    private static function within(EngineField $field, float $number): bool
    {
        return ($field->min === null || $number >= $field->min) && ($field->max === null || $number <= $field->max);
    }
}
