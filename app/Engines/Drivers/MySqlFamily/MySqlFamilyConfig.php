<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MySqlFamily;

use App\Engines\Contracts\Config;
use App\Engines\Data\EngineField;
use App\Engines\Drivers\Support\NodeState;
use App\Engines\Scripts\MySqlSql;
use App\Engines\Settings\Setting;
use App\Exceptions\Engines\EngineBusy;

final readonly class MySqlFamilyConfig implements Config
{
    private const array VARIABLES = ['default_time_zone' => 'time_zone'];

    private const array NUMERIC_KINDS = [Setting::BYTES, Setting::INTEGER, Setting::DECIMAL];

    public function __construct(
        private MySqlFamilyDriver $driver,
        private FamilyScripts $scripts,
        private MySqlFamilyConfiguration $configuration,
        private NodeState $state,
    ) {}

    public function apply(): void
    {
        $request = $this->configuration->savedRequestOf();
        $effective = $this->configuration->catalogOf()->effective($this->configuration->tunedOf($request), $request->settings);
        ksort($effective, SORT_STRING);
        $static = $this->staticKeysOf();
        $mysql = $this->driver->engine()->id === 'mysql';
        $verb = $mysql ? 'SET PERSIST' : 'SET GLOBAL';
        $sets = [];

        foreach ($effective as $key => $value) {
            if (! in_array($key, $static, true)) {
                $sets[] = "{$verb} {$key} = ".$this->literalOf($value);
            }
        }

        $this->scripts->run($this->driver, 'engines.mysql-family.config.apply', [
            'SETS' => json_encode($sets, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'KEYS' => json_encode(array_keys($effective), JSON_THROW_ON_ERROR),
            'STATIC' => json_encode($static, JSON_THROW_ON_ERROR),
            'FORGET' => $mysql ? 'RESET PERSIST IF EXISTS' : '',
        ]);
    }

    public function sync(): void {}

    public function pendingRestart(): array
    {
        $request = $this->configuration->savedRequestOf();
        $catalog = $this->configuration->catalogOf();
        $effective = $catalog->effective($this->configuration->tunedOf($request), $request->settings);
        $names = implode(',', array_map(fn (EngineField $field): string => "'".$this->variableOf($field->key)."'", $catalog->fields));
        $live = $this->scripts->read($this->driver, 'engines.mysql-family.config.show', ['NAMES' => $names])->stringMap('values');
        $pending = [];

        foreach ($catalog->fields as $field) {
            $value = $live[$this->variableOf($field->key)] ?? null;

            if ($value !== null && $field->restart && isset($effective[$field->key]) && ! $this->sameValue($field, $value, $effective[$field->key])) {
                $pending[] = $field->key;
            }
        }

        return $pending;
    }

    public function fields(): array
    {
        $catalog = $this->configuration->catalogOf();

        try {
            $request = $this->configuration->savedRequestOf();
        } catch (EngineBusy) {
            return $catalog->fields;
        }

        return $catalog->fieldsWith($this->configuration->tunedOf($request));
    }

    public function tune(): array
    {
        $request = $this->configuration->savedRequestOf();
        $catalog = $this->configuration->catalogOf();
        $previous = $this->configuration->tunedOf($request);
        $tuned = $catalog->tuned(NodeState::selfOf($request));
        $this->state->saveTuned($this->driver, MySqlFamilyConfiguration::TUNED_STATE, $tuned);
        $this->configuration->configure($request);
        $this->apply();

        return $catalog->restartChanged($previous, $tuned, $request->settings);
    }

    /**
     * @return list<string>
     */
    private function staticKeysOf(): array
    {
        $restarting = array_filter($this->configuration->catalogOf()->fields, fn (EngineField $field): bool => $field->restart);

        return array_values(array_map(fn (EngineField $field): string => $field->key, $restarting));
    }

    private function literalOf(string $value): string
    {
        $bytes = Setting::bytesOf($value);

        if ($bytes !== null) {
            return sprintf('%.0f', $bytes);
        }

        if (is_numeric($value)) {
            return str_replace('E', 'e', (string) (float) $value);
        }

        return (new MySqlSql)->string($value);
    }

    private function variableOf(string $key): string
    {
        return self::VARIABLES[$key] ?? $key;
    }

    private function sameValue(EngineField $field, string $live, string $wanted): bool
    {
        if (! in_array($field->kind, self::NUMERIC_KINDS, true)) {
            return strcasecmp($live, $wanted) === 0;
        }

        $left = $this->numberOf($field, $live);

        return $left !== null && $left === $this->numberOf($field, $wanted);
    }

    private function numberOf(EngineField $field, string $value): ?float
    {
        if ($field->kind === Setting::BYTES) {
            return Setting::bytesOf($value);
        }

        return is_numeric($value) ? (float) $value : null;
    }
}
