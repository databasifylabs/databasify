<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Engines\EngineClient;
use App\Engines\Requests\ConfigFields;
use App\Engines\Requests\EngineCapabilities;
use App\Engines\Requests\LogsRead;
use App\Models\Database;
use App\Models\DatabaseNode;

final readonly class LearnEngine
{
    public function __construct(private EngineClient $engine) {}

    public function handle(Database $database, DatabaseNode $node): void
    {
        $database->update([
            'engine_capabilities' => $this->engine->send($node, new EngineCapabilities, "{$node->server->name}: reading what the engine offers"),
            'config_fields' => $this->engine->send($node, new ConfigFields, "{$node->server->name}: reading the engine settings")->fields,
            'log_units' => $this->engine->send($node, new LogsRead(null, 1))->units,
        ]);
    }
}
