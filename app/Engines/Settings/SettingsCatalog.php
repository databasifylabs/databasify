<?php

declare(strict_types=1);

namespace App\Engines\Settings;

use App\Engines\Data\EngineField;
use App\Engines\Data\Member;
use App\Exceptions\Engines\InvalidEngineRequest;
use Closure;

final readonly class SettingsCatalog
{
    public const string SOURCE_USER = 'user';

    public const string SOURCE_TUNED = 'tuned';

    public const string SOURCE_DEFAULT = 'default';

    /**
     * @param  list<EngineField>  $fields
     * @param  Closure(Member): array<string, string>  $tune
     */
    public function __construct(
        public array $fields,
        private Closure $tune,
    ) {}

    /**
     * @param  array<string, string>  $settings
     */
    public function check(array $settings): void
    {
        ksort($settings);

        foreach ($settings as $key => $value) {
            $field = $this->field($key);

            if ($field === null) {
                throw new InvalidEngineRequest('invalid_request', "setting \"{$key}\" is not offered; config fields lists the settings");
            }

            if (! Setting::allows($field, $value)) {
                throw new InvalidEngineRequest('invalid_request', "setting \"{$key}\" does not accept \"{$value}\"");
            }
        }
    }

    /**
     * @return array<string, string>
     */
    public function tuned(Member $resources): array
    {
        return ($this->tune)($resources);
    }

    /**
     * @param  array<string, string>  $tuned
     * @param  array<string, string>  $user
     * @return array<string, string>
     */
    public function effective(array $tuned, array $user): array
    {
        return [...$tuned, ...$user];
    }

    /**
     * @param  array<string, string>  $tuned
     * @return list<EngineField>
     */
    public function fieldsWith(array $tuned): array
    {
        return array_map(fn (EngineField $field): EngineField => array_key_exists($field->key, $tuned) ? $this->withDefault($field, $tuned[$field->key]) : $field, $this->fields);
    }

    /**
     * @param  array<string, string>  $tuned
     * @param  array<string, string>  $user
     */
    public function sourceOf(string $key, array $tuned, array $user): string
    {
        return match (true) {
            array_key_exists($key, $user) => self::SOURCE_USER,
            array_key_exists($key, $tuned) => self::SOURCE_TUNED,
            default => self::SOURCE_DEFAULT,
        };
    }

    /**
     * @param  array<string, string>  $previous
     * @param  array<string, string>  $tuned
     * @param  array<string, string>  $user
     * @return list<string>
     */
    public function restartChanged(array $previous, array $tuned, array $user): array
    {
        $changed = array_filter($this->fields, fn (EngineField $field): bool => $field->restart && ! array_key_exists($field->key, $user) && ($previous[$field->key] ?? '') !== ($tuned[$field->key] ?? ''));

        return array_values(array_map(fn (EngineField $field): string => $field->key, $changed));
    }

    private function withDefault(EngineField $field, string $default): EngineField
    {
        return new EngineField($field->key, $field->label, $field->description, $field->kind, $default, $field->options, $field->unit, $field->min, $field->max, $field->pattern, $field->restart, $field->tuned);
    }

    private function field(string $key): ?EngineField
    {
        foreach ($this->fields as $field) {
            if ($field->key === $key) {
                return $field;
            }
        }

        return null;
    }
}
