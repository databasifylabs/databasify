<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MySqlFamily;

use App\Contracts\Engines\EngineScripts;
use App\Engines\Responses\Operation;
use App\Engines\Scripts\ScriptResult;
use App\Models\DatabaseNode;

final readonly class FamilyScripts
{
    public function __construct(private EngineScripts $scripts) {}

    /**
     * @param  array<string, string>  $env
     */
    public function run(DatabaseNode $node, string $script, array $env = [], int $timeout = 120): ScriptResult
    {
        return $this->scripts->run($node, $this->nameOf($node, $script), ['engine' => self::engineOf($node)], timeout: $timeout, env: $env);
    }

    /**
     * @param  array<string, string>  $env
     */
    public function start(DatabaseNode $node, string $key, string $script, array $env = []): Operation
    {
        return $this->scripts->start($node, $key, $this->nameOf($node, $script), ['engine' => self::engineOf($node)], env: $env);
    }

    public static function engineOf(DatabaseNode $node): MySqlFamilyEngine
    {
        return MySqlFamilyEngine::of($node->database->engine);
    }

    private function nameOf(DatabaseNode $node, string $script): string
    {
        return str_replace('{engine}', self::engineOf($node)->id, $script);
    }
}
