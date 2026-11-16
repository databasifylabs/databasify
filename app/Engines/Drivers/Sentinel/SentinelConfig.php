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
        private SentinelConfiguration $configuration,
        private NodeState $state,
    ) {}

    public function apply(): void
    {
        $desired = $this->desiredOf($this->liveSettings());
        ksort($desired, SORT_STRING);
        $restartOnly = $this->configuration->restartOnlyKeys();

        $this->driver->run('engines.sentinel.config.apply', [
            'desired' => $desired,
            'removed' => collect($this->configuration->catalog()->fields)
                ->reject(fn (EngineField $field): bool => in_array($field->key, $restartOnly, true))
                ->map(fn (EngineField $field): string => SentinelFiles::configKeyOf($field->key))
                ->diff(array_keys($desired))
                ->unique()
                ->values()
                ->all(),
        ]);
    }

    public function sync(): void {}

    public function pendingRestart(): array
    {
        $effective = $this->configuration->effectiveOf($this->configuration->savedRequestOf());
        $fields = collect($this->configuration->catalog()->fields)->filter(fn (EngineField $field): bool => $field->restart && isset($effective[$field->key]));

        if ($fields->isEmpty()) {
            return [];
        }

        $names = $fields->map(fn (EngineField $field): string => SentinelFiles::configKeyOf($field->key))->all();
        $live = new SentinelReply($this->driver->read('engines.sentinel.config.show', ['names' => $names])->string('config'));

        return array_values($fields
            ->filter(fn (EngineField $field): bool => strcasecmp($live->valueOf(SentinelFiles::configKeyOf($field->key)) ?? '', $effective[$field->key] ?? '') !== 0)
            ->map(fn (EngineField $field): string => $field->key)
            ->all());
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
}
