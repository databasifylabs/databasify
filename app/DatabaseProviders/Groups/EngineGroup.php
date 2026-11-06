<?php

declare(strict_types=1);

namespace App\DatabaseProviders\Groups;

use App\DatabaseProviders\Contracts\DatabaseDriver;
use App\Models\Database;
use App\Models\DatabaseNode;

abstract readonly class EngineGroup
{
    public function __construct(protected DatabaseDriver $engine, protected Database $database) {}

    protected function scriptData(): EngineScriptData
    {
        return $this->engine->scriptData();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function runStep(DatabaseNode $node, string $script, array $data, ?string $log = null, ?int $timeout = null): string
    {
        return $node->ssh()->exec($this->engine->scriptView($script), $data, $log, $timeout);
    }
}
