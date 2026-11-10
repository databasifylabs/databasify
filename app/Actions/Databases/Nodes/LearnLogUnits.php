<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Engines\EngineClient;
use App\Engines\Requests\LogsRead;
use App\Models\Database;
use App\Models\DatabaseNode;

final readonly class LearnLogUnits
{
    public function __construct(private EngineClient $engine) {}

    public function handle(Database $database, DatabaseNode $node): void
    {
        $database->update(['log_units' => $this->engine->send($node, new LogsRead(null, 1))->units]);
    }
}
