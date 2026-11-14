<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Engines\Contracts\Cluster;
use App\Engines\Contracts\Config;
use App\Engines\Contracts\Nodes;
use App\Engines\Data\EngineFacts;
use App\Engines\Drivers\AbstractEngineDriver;
use LogicException;

final readonly class ScriptedDriver extends AbstractEngineDriver
{
    public static function facts(string $line): EngineFacts
    {
        throw new LogicException('The scripted driver declares no facts.');
    }

    public function nodes(): Nodes
    {
        throw new LogicException('The scripted driver has no nodes group.');
    }

    public function cluster(): Cluster
    {
        throw new LogicException('The scripted driver has no cluster group.');
    }

    public function config(): Config
    {
        throw new LogicException('The scripted driver has no config group.');
    }
}
