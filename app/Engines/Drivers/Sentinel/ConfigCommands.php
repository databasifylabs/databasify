<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Sentinel;

use App\Engines\Data\EngineField;
use App\Engines\Drivers\NodeState;
use App\Engines\Responses\ConfigFields;
use App\Engines\Responses\ConfigShow;
use App\Engines\Responses\ConfigTune;
use App\Engines\Responses\Done;
use App\Exceptions\Engines\EngineBusy;
use App\Models\DatabaseNode;

final readonly class ConfigCommands
{
    public function __construct(
        private SentinelScripts $scripts,
        private Configuration $configuration,
        private NodeState $state,
    ) {}

    public function apply(DatabaseNode $node): Done
    {
        $request = $this->configuration->savedRequestOf($node);
        $catalog = $this->configuration->catalogOf($node);
        $settings = array_diff_key($this->configuration->effectiveOf($node, $request), array_flip(SentinelSettings::restartOnlyKeys(SentinelScripts::engineOf($node), $node->database->version_line)));
        $desired = SentinelFiles::persistent($settings) ? ['appendonly' => 'yes', 'save' => SentinelFiles::SNAPSHOT_SCHEDULE] : ['appendonly' => 'no', 'save' => ''];

        foreach ($settings as $key => $value) {
            [$name, $setting] = array_pad(explode(' ', SentinelFiles::directiveOf($key, $value), 2), 2, '');
            $desired[$name] = trim($setting, '"');
        }

        $configKeys = [];

        foreach ($catalog->fields as $field) {
            $configKeys[$field->key] = SentinelFiles::configKeyOf($field->key);
        }

        $keys = array_keys($settings);
        sort($keys, SORT_STRING);

        $this->scripts->run($node, 'config/apply', [
            'DESIRED' => json_encode((object) $desired, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'SETTING_KEYS' => json_encode($keys, JSON_THROW_ON_ERROR),
            'CONFIG_KEYS' => json_encode($configKeys, JSON_THROW_ON_ERROR),
        ]);

        return new Done;
    }

    public function sync(): Done
    {
        return new Done;
    }

    public function show(DatabaseNode $node): ConfigShow
    {
        $effective = $this->configuration->effectiveOf($node, $this->configuration->savedRequestOf($node));
        $fields = $this->configuration->catalogOf($node)->fields;
        $names = array_values(array_unique(array_map(fn (EngineField $field): string => SentinelFiles::configKeyOf($field->key), $fields)));
        $live = $this->scripts->run($node, 'config/show', ['NAMES' => implode(' ', $names)])->json()->stringMap('values');
        $pending = array_filter($fields, fn (EngineField $field): bool => $field->restart && isset($effective[$field->key]) && strcasecmp($live[SentinelFiles::configKeyOf($field->key)] ?? '', $effective[$field->key]) !== 0);

        return new ConfigShow(array_values(array_map(fn (EngineField $field): string => $field->key, $pending)));
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
}
