<?php

declare(strict_types=1);

namespace App\Actions\Databases\Monitoring;

use App\Models\DatabaseNode;

final readonly class ReadNodeLogs
{
    public function handle(DatabaseNode $node, string $service, int $lines = 200): string
    {
        return $node->ssh()->exec('ssh.databases.monitoring.logs', [
            'container' => $node->database->provider()->container(),
            'unit' => $service,
            'lines' => $lines,
        ]);
    }
}
