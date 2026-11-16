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

    private const array HOT_STANDBY_SETTINGS = ['max_connections', 'max_locks_per_transaction', 'max_prepared_transactions', 'max_wal_senders', 'max_worker_processes'];

    public function __construct(
        private PostgresDriver $driver,
        private NodeState $state,
        private PostgresConfiguration $configuration,
    ) {}

    public function apply(): void
    {
        $this->driver->run('engines.postgres.config.apply', ['clustered' => $this->configuration->savedRequestOf()->clustered], timeout: self::APPLY_TIMEOUT);
    }

    public function sync(): void
    {
        $request = $this->configuration->savedRequestOf();

        if (! $request->clustered) {
            return;
        }

        [$clusterParameters] = PostgresFiles::parametersOf($this->configuration->effectiveOf($request, $this->tunedOf($request)));
        $live = $this->driver->queryJson('engines.postgres.config.limits', ['names' => self::HOT_STANDBY_SETTINGS]);
        $current = $live->stringMap('current');
        $defaults = $live->stringMap('defaults');
        $restartPrimary = collect(self::HOT_STANDBY_SETTINGS)->contains(fn (string $name): bool => isset($current[$name], $defaults[$name]) && (int) ($clusterParameters[$name] ?? $defaults[$name]) < (int) $current[$name]);

        $this->driver->run('engines.postgres.config.sync', ['restartPrimary' => $restartPrimary], timeout: self::SYNC_TIMEOUT);
    }

    public function pendingRestart(): array
    {
        $keys = array_map(fn (EngineField $field): string => $field->key, $this->configuration->catalog()->fields);
        $values = $this->driver->queryJson('engines.postgres.config.show', ['keys' => $keys])->objects('values');

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
        $config = $this->configuration->savedConfig() ?? throw new EngineBusy('run node configure first');
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
