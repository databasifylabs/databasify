<?php

declare(strict_types=1);

namespace App\Actions\Databases\Monitoring;

use App\Engines\EngineClient;
use App\Engines\Requests\LogsRead;
use App\Models\DatabaseNode;

final readonly class ReadNodeLogs
{
    public function __construct(private EngineClient $engine) {}

    public function handle(DatabaseNode $node, string $service, int $lines = 200): string
    {
        return implode("\n", $this->engine->send($node, new LogsRead($service, $lines))->lines);
    }
}
