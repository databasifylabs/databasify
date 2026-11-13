<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Sentinel;

use App\Engines\Contracts\Config;
use App\Engines\Data\EngineField;
use App\Engines\Drivers\Support\NodeState;
use App\Exceptions\Engines\EngineBusy;

final readonly class SentinelConfig implements Config
{
    public function __construct(
        private SentinelDriver $driver,
        private SentinelScripts $scripts,
        private SentinelConfiguration $configuration,
        private NodeState $state,
    ) {}

    public function apply(): void
    {
        $settings = $this->liveSettings();
        $keys = array_keys($settings);
        sort($keys, SORT_STRING);

        $this->scripts->run($this->driver, 'config/apply', [
            'DESIRED' => json_encode((object) $this->desiredOf($settings), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'SETTING_KEYS' => json_encode($keys, JSON_THROW_ON_ERROR),
            'CONFIG_KEYS' => json_encode($this->configKeys(), JSON_THROW_ON_ERROR),
        ]);
    }

    public function sync(): void {}

    public function pendingRestart(): array
    {
        $effective = $this->configuration->effectiveOf($this->configuration->savedRequestOf());
        $fields = $this->configuration->catalog()->fields;
        $names = array_values(array_unique(array_map(fn (EngineField $field): string => SentinelFiles::configKeyOf($field->key), $fields)));
        $live = $this->scripts->read($this->driver, 'config/show', ['NAMES' => implode(' ', $names)])->stringMap('values');
        $pending = array_filter($fields, fn (EngineField $field): bool => $field->restart && isset($effective[$field->key]) && strcasecmp($live[SentinelFiles::configKeyOf($field->key)] ?? '', $effective[$field->key]) !== 0);

        return array_values(array_map(fn (EngineField $field): string => $field->key, $pending));
    }

    public function fields(): array
    {
        $catalog = $this->configuration->catalog();

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
        $catalog = $this->configuration->catalog();
        $previous = $this->configuration->tunedOf($request);
        $tuned = $catalog->tuned(NodeState::selfOf($request));
        $this->state->saveTuned($this->driver, SentinelConfiguration::TUNED_STATE, $tuned);
        $this->configuration->configure($request);
        $this->apply();

        return $catalog->restartChanged($previous, $tuned, $request->settings);
    }

    /**
     * @return array<string, string>
     */
    private function liveSettings(): array
    {
        $effective = $this->configuration->effectiveOf($this->configuration->savedRequestOf());

        return array_diff_key($effective, array_flip($this->configuration->restartOnlyKeys()));
    }

    /**
     * @param  array<string, string>  $settings
     * @return array<string, string>
     */
    private function desiredOf(array $settings): array
    {
        $desired = SentinelFiles::persistent($settings) ? ['appendonly' => 'yes', 'save' => SentinelFiles::SNAPSHOT_SCHEDULE] : ['appendonly' => 'no', 'save' => ''];

        foreach ($settings as $key => $value) {
            [$name, $setting] = array_pad(explode(' ', SentinelFiles::directiveOf($key, $value), 2), 2, '');
            $desired[$name] = trim($setting, '"');
        }

        return $desired;
    }

    /**
     * @return array<string, string>
     */
    private function configKeys(): array
    {
        $configKeys = [];

        foreach ($this->configuration->catalog()->fields as $field) {
            $configKeys[$field->key] = SentinelFiles::configKeyOf($field->key);
        }

        return $configKeys;
    }
}
