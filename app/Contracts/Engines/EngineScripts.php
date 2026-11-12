<?php

declare(strict_types=1);

namespace App\Contracts\Engines;

use App\Engines\Responses\Operation;
use App\Engines\Scripts\ScriptResult;
use App\Engines\Scripts\ScriptRunner;
use App\Models\DatabaseNode;
use Illuminate\Container\Attributes\Bind;

#[Bind(ScriptRunner::class)]
interface EngineScripts
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $env
     */
    public function run(DatabaseNode $node, string $script, array $data = [], ?string $user = null, int $timeout = 120, ?string $log = null, array $env = []): ScriptResult;

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $env
     */
    public function start(DatabaseNode $node, string $key, string $script, array $data = [], ?string $user = null, array $env = []): Operation;

    public function phaseOf(DatabaseNode $node, string $key): ?string;
}
