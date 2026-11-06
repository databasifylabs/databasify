<?php

declare(strict_types=1);

namespace App\DatabaseProviders\Concerns;

use App\Models\DatabaseNode;

trait SkipsRestartOnSameConfig
{
    private const string RUNS_CURRENT_CONFIG = 'runs the current configuration';

    public function skipsRestartOnSameConfig(): bool
    {
        return true;
    }

    public function runsCurrentConfig(DatabaseNode $node): bool
    {
        $output = $this->runStep($node, 'lifecycle.runs-current-config', $this->scriptData()->for($node), "{$node->server->name}: checking whether the new configuration needs a restart");

        return str_contains($output, self::RUNS_CURRENT_CONFIG);
    }
}
