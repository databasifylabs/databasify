<?php

declare(strict_types=1);

namespace App\Engines\Scripts;

use App\Models\DatabaseNode;

final readonly class ScriptEnvironment
{
    /**
     * @param  array<string, string>  $env
     * @return array<string, string>
     */
    public static function of(DatabaseNode $node, array $env): array
    {
        $container = ['DATABASIFY_ENGINE' => $node->database->engine, 'DATABASIFY_LINE' => $node->database->version_line, 'HOME' => '/root'];

        return [...$container, ...array_diff_key($env, $container)];
    }
}
