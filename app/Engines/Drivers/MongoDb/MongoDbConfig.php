<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MongoDb;

use App\Engines\Contracts\Config;
use App\Engines\Data\EngineField;
use App\Engines\Drivers\Support\NodeState;
use App\Exceptions\Engines\EngineBusy;

final readonly class MongoDbConfig implements Config
{
    public function __construct(
        private MongoDbDriver $driver,
        private MongoDbConfiguration $configuration,
        private NodeState $state,
    ) {}

    public function apply(): void
    {
        $value = $this->valuesOf();

        $this->driver->run('mongodb/config/apply', env: [
            'VERBOSITY' => (string) (int) $value(MongoDbSettings::VERBOSITY),
            'LIFETIME' => (string) (int) $value(MongoDbSettings::TRANSACTIONS),
            'SLOW' => (string) (int) $value(MongoDbSettings::SLOW_OPERATION),
            'CACHE_MB' => (string) (int) ((float) $value(MongoDbSettings::CACHE_SIZE) * 1024),
            'READ_CONCERN' => $value(MongoDbSettings::READ_CONCERN),
            'WRITE_CONCERN' => $value(MongoDbSettings::WRITE_CONCERN),
        ]);
    }

    public function sync(): void {}

    public function pendingRestart(): array
    {
        $effective = $this->configuration->effectiveOf($this->configuration->savedRequestOf());
        $live = $this->driver->read('mongodb/config/show')->stringMap('values');
        $pending = array_filter(MongoDbSettings::catalog()->fields, fn (EngineField $field): bool => $field->restart && isset($effective[$field->key]) && ($live[$field->key] ?? '') !== $effective[$field->key]);

        return array_values(array_map(fn (EngineField $field): string => $field->key, $pending));
    }

    public function fields(): array
    {
        try {
            $request = $this->configuration->savedRequestOf();
        } catch (EngineBusy) {
            return MongoDbSettings::catalog()->fields;
        }

        return MongoDbSettings::catalog()->fieldsWith($this->configuration->tunedOf($request));
    }

    public function tune(): array
    {
        $request = $this->configuration->savedRequestOf();
        $catalog = MongoDbSettings::catalog();
        $previous = $this->configuration->tunedOf($request);
        $tuned = $catalog->tuned(NodeState::selfOf($request));
        $this->state->saveTuned($this->driver, MongoDbConfiguration::TUNED_STATE, $tuned);
        $this->configuration->configure($request);
        $this->apply();

        return $catalog->restartChanged($previous, $tuned, $request->settings);
    }

    /**
     * @return callable(string): string
     */
    private function valuesOf(): callable
    {
        $effective = $this->configuration->effectiveOf($this->configuration->savedRequestOf());
        $defaults = MongoDbSettings::defaults();

        return fn (string $key): string => $effective[$key] ?? $defaults[$key] ?? '';
    }
}
