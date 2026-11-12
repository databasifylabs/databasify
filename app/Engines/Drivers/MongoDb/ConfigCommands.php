<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MongoDb;

use App\Contracts\Engines\EngineScripts;
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
        private EngineScripts $scripts,
        private Configuration $configuration,
        private NodeState $state,
    ) {}

    public function apply(DatabaseNode $node): Done
    {
        $value = $this->valuesOf($node);

        $this->scripts->run($node, 'mongodb/config/apply', env: [
            'VERBOSITY' => (string) (int) $value(MongoDbSettings::VERBOSITY),
            'LIFETIME' => (string) (int) $value(MongoDbSettings::TRANSACTIONS),
            'SLOW' => (string) (int) $value(MongoDbSettings::SLOW_OPERATION),
            'CACHE_MB' => (string) (int) ((float) $value(MongoDbSettings::CACHE_SIZE) * 1024),
            'READ_CONCERN' => $value(MongoDbSettings::READ_CONCERN),
            'WRITE_CONCERN' => $value(MongoDbSettings::WRITE_CONCERN),
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
        $live = $this->scripts->run($node, 'mongodb/config/show')->json()->stringMap('values');
        $pending = array_filter(MongoDbSettings::catalog()->fields, fn (EngineField $field): bool => $field->restart && isset($effective[$field->key]) && ($live[$field->key] ?? '') !== $effective[$field->key]);

        return new ConfigShow(array_values(array_map(fn (EngineField $field): string => $field->key, $pending)));
    }

    public function fields(DatabaseNode $node): ConfigFields
    {
        try {
            $request = $this->configuration->savedRequestOf($node);
        } catch (EngineBusy) {
            return new ConfigFields(MongoDbSettings::catalog()->fields);
        }

        return new ConfigFields(MongoDbSettings::catalog()->fieldsWith($this->configuration->tunedOf($node, $request)));
    }

    public function tune(DatabaseNode $node): ConfigTune
    {
        $request = $this->configuration->savedRequestOf($node);
        $catalog = MongoDbSettings::catalog();
        $previous = $this->configuration->tunedOf($node, $request);
        $tuned = $catalog->tuned(NodeState::selfOf($request));
        $this->state->saveTuned($node, Configuration::TUNED_STATE, $tuned);
        $this->configuration->configure($node, $request);
        $this->apply($node);

        return new ConfigTune($catalog->restartChanged($previous, $tuned, $request->settings));
    }

    /**
     * @return callable(string): string
     */
    private function valuesOf(DatabaseNode $node): callable
    {
        $effective = $this->configuration->effectiveOf($node, $this->configuration->savedRequestOf($node));
        $defaults = [];

        foreach (MongoDbSettings::catalog()->fields as $field) {
            $defaults[$field->key] = $field->default;
        }

        return fn (string $key): string => $effective[$key] ?? $defaults[$key] ?? '';
    }
}
