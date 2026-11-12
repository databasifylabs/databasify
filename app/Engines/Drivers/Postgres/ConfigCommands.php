<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Postgres;

use App\Contracts\Engines\EngineScripts;
use App\Engines\Data\EngineField;
use App\Engines\Drivers\NodeState;
use App\Engines\Requests\NodeConfigure;
use App\Engines\Responses\ConfigFields;
use App\Engines\Responses\ConfigShow;
use App\Engines\Responses\ConfigTune;
use App\Engines\Responses\Done;
use App\Exceptions\Engines\EngineBusy;
use App\Models\DatabaseNode;

final readonly class ConfigCommands
{
    private const int SYNC_TIMEOUT = 720;

    public function __construct(
        private EngineScripts $scripts,
        private NodeState $state,
        private Accounts $accounts,
        private Configuration $configuration,
    ) {}

    public function apply(DatabaseNode $node): Done
    {
        $this->scripts->run($node, 'postgres/config/apply', $this->accounts->dataFor($node), timeout: 90);

        return new Done;
    }

    public function sync(DatabaseNode $node): Done
    {
        $this->scripts->run($node, 'postgres/config/sync', $this->accounts->dataFor($node), timeout: self::SYNC_TIMEOUT);

        return new Done;
    }

    public function show(DatabaseNode $node): ConfigShow
    {
        $keys = implode(',', array_map(fn (EngineField $field): string => $field->key, $this->configuration->catalogOf($node)->fields));
        $reply = $this->scripts->run($node, 'postgres/config/show', $this->accounts->dataFor($node), env: ['KEYS' => $keys])->json();

        return ConfigShow::fromPayload($reply);
    }

    public function fields(DatabaseNode $node): ConfigFields
    {
        $catalog = $this->configuration->catalogOf($node);
        $request = $this->configuration->savedRequestOf($node);

        if ($request === null) {
            return new ConfigFields($catalog->fields);
        }

        return new ConfigFields($catalog->fieldsWith($this->tunedOf($node, $request)));
    }

    public function tune(DatabaseNode $node): ConfigTune
    {
        $request = $this->configuration->savedRequestOf($node) ?? throw new EngineBusy('busy', 'run node configure first');
        $catalog = $this->configuration->catalogOf($node);
        $previous = $this->tunedOf($node, $request);
        $tuned = $catalog->tuned(NodeState::selfOf($request));
        $this->saveTuned($node, $tuned);
        $this->configuration->configure($node, $request);
        $this->sync($node);
        $this->apply($node);

        return new ConfigTune($catalog->restartChanged($previous, $tuned, $request->settings));
    }

    /**
     * @return array<string, string>
     */
    private function tunedOf(DatabaseNode $node, NodeConfigure $request): array
    {
        $saved = $this->configuration->savedTunedOf($node);

        if ($saved !== null) {
            return $saved;
        }

        $tuned = $this->configuration->catalogOf($node)->tuned(NodeState::selfOf($request));
        $this->saveTuned($node, $tuned);

        return $tuned;
    }

    /**
     * @param  array<string, string>  $tuned
     */
    private function saveTuned(DatabaseNode $node, array $tuned): void
    {
        $this->state->saveTuned($node, Configuration::TUNED_STATE, $tuned);
    }
}
