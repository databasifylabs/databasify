<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MySqlFamily;

use App\Engines\Data\EngineField;
use App\Engines\Drivers\NodeState;
use App\Engines\Responses\ConfigFields;
use App\Engines\Responses\ConfigShow;
use App\Engines\Responses\ConfigTune;
use App\Engines\Responses\Done;
use App\Engines\Scripts\MySqlSql;
use App\Engines\Settings\Setting;
use App\Exceptions\Engines\EngineBusy;
use App\Models\DatabaseNode;

final readonly class ConfigCommands
{
    private const array VARIABLES = ['default_time_zone' => 'time_zone'];

    private const array NUMERIC_KINDS = [Setting::BYTES, Setting::INTEGER, Setting::DECIMAL];

    public function __construct(
        private FamilyScripts $scripts,
        private Configuration $configuration,
        private NodeState $state,
    ) {}

    public function apply(DatabaseNode $node): Done
    {
        $request = $this->configuration->savedRequestOf($node);
        $effective = $this->configuration->catalogOf($node)->effective($this->configuration->tunedOf($node, $request), $request->settings);
        ksort($effective, SORT_STRING);
        $static = $this->staticKeysOf($node);
        $mysql = FamilyScripts::engineOf($node)->id === 'mysql';
        $verb = $mysql ? 'SET PERSIST' : 'SET GLOBAL';
        $sets = [];

        foreach ($effective as $key => $value) {
            if (! in_array($key, $static, true)) {
                $sets[] = "{$verb} {$key} = ".$this->literalOf($value);
            }
        }

        $this->scripts->run($node, 'mysql-family/config/apply', [
            'SETS' => json_encode($sets, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'KEYS' => json_encode(array_keys($effective), JSON_THROW_ON_ERROR),
            'STATIC' => json_encode($static, JSON_THROW_ON_ERROR),
            'FORGET' => $mysql ? 'RESET PERSIST IF EXISTS' : '',
        ]);

        return new Done;
    }

    public function sync(): Done
    {
        return new Done;
    }

    public function show(DatabaseNode $node): ConfigShow
    {
        $request = $this->configuration->savedRequestOf($node);
        $catalog = $this->configuration->catalogOf($node);
        $effective = $catalog->effective($this->configuration->tunedOf($node, $request), $request->settings);
        $names = implode(',', array_map(fn (EngineField $field): string => "'".$this->variableOf($field->key)."'", $catalog->fields));
        $live = $this->scripts->run($node, 'mysql-family/config/show', ['NAMES' => $names])->json()->stringMap('values');
        $pending = [];

        foreach ($catalog->fields as $field) {
            $value = $live[$this->variableOf($field->key)] ?? null;

            if ($value !== null && $field->restart && isset($effective[$field->key]) && ! $this->sameValue($field, $value, $effective[$field->key])) {
                $pending[] = $field->key;
            }
        }

        return new ConfigShow($pending);
    }

    public function fields(DatabaseNode $node): ConfigFields
    {
        $catalog = $this->configuration->catalogOf($node);

        try {
            $request = $this->configuration->savedRequestOf($node);
        } catch (EngineBusy) {
            return new ConfigFields($catalog->fields);
        }

        return new ConfigFields($catalog->fieldsWith($this->configuration->tunedOf($node, $request)));
    }

    public function tune(DatabaseNode $node): ConfigTune
    {
        $request = $this->configuration->savedRequestOf($node);
        $catalog = $this->configuration->catalogOf($node);
        $previous = $this->configuration->tunedOf($node, $request);
        $tuned = $catalog->tuned(NodeState::selfOf($request));
        $this->state->saveTuned($node, Configuration::TUNED_STATE, $tuned);
        $this->configuration->configure($node, $request);
        $this->apply($node);

        return new ConfigTune($catalog->restartChanged($previous, $tuned, $request->settings));
    }

    /**
     * @return list<string>
     */
    private function staticKeysOf(DatabaseNode $node): array
    {
        $restarting = array_filter($this->configuration->catalogOf($node)->fields, fn (EngineField $field): bool => $field->restart);

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
        $right = $this->numberOf($field, $wanted);

        return $left !== null && $right !== null && $left === $right;
    }

    private function numberOf(EngineField $field, string $value): ?float
    {
        if ($field->kind === Setting::BYTES) {
            return Setting::bytesOf($value);
        }

        return is_numeric($value) ? (float) $value : null;
    }
}
