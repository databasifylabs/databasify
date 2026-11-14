<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Postgres;

use App\Engines\Contracts\Config;
use App\Engines\Data\EngineField;
use App\Engines\Data\NodeConfig;
use App\Engines\Drivers\Support\NodeState;
use App\Engines\Payload;
use App\Exceptions\Engines\EngineBusy;

final readonly class PostgresConfig implements Config
{
    private const int APPLY_TIMEOUT = 90;

    private const int SYNC_TIMEOUT = 720;

    public function __construct(
        private PostgresDriver $driver,
        private NodeState $state,
        private PostgresConfiguration $configuration,
    ) {}

    public function apply(): void
    {
        $this->driver->run('postgres/config/apply', $this->driver->data(), timeout: self::APPLY_TIMEOUT);
    }

    public function sync(): void
    {
        $this->driver->run('postgres/config/sync', $this->driver->data(), timeout: self::SYNC_TIMEOUT);
    }

    public function pendingRestart(): array
    {
        $keys = implode(',', array_map(fn (EngineField $field): string => $field->key, $this->configuration->catalog()->fields));
        $values = $this->driver->read('postgres/config/show', $this->driver->data(), env: ['KEYS' => $keys])->objects('values');

        return array_values(array_map(fn (Payload $value): string => $value->string('key'), array_filter($values, fn (Payload $value): bool => $value->bool('pendingRestart'))));
    }

    public function fields(): array
    {
        $catalog = $this->configuration->catalog();
        $config = $this->configuration->savedConfig();

        return $config === null ? $catalog->fields : $catalog->fieldsWith($this->tunedOf($config));
    }

    public function tune(): array
    {
        $config = $this->configuration->savedConfig() ?? throw new EngineBusy('busy', 'run node configure first');
        $catalog = $this->configuration->catalog();
        $previous = $this->tunedOf($config);
        $tuned = $catalog->tuned(NodeState::selfOf($config));
        $this->state->saveTuned($this->driver, PostgresConfiguration::TUNED_STATE, $tuned);
        $this->configuration->configure($config);
        $this->sync();
        $this->apply();

        return $catalog->restartChanged($previous, $tuned, $config->settings);
    }

    /**
     * @return array<string, string>
     */
    private function tunedOf(NodeConfig $config): array
    {
        return $this->state->tunedOrNewOf($this->driver, PostgresConfiguration::TUNED_STATE, $this->configuration->catalog(), $config);
    }
}
