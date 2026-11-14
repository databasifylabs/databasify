<?php

declare(strict_types=1);

namespace App\Actions\Databases\Monitoring;

use App\Engines\Runtime\Runtime;
use App\Models\DatabaseNode;

final readonly class ReadNodeLogs
{
    public function __construct(private Runtime $runtime) {}

    public function handle(DatabaseNode $node, string $service, int $lines = 200): string
    {
        return implode("\n", $this->runtime->readLogs($node, $service, $lines)->lines);
    }
}
