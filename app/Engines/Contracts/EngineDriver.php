<?php

declare(strict_types=1);

namespace App\Engines\Contracts;

use App\Engines\Data\EngineFacts;

interface EngineDriver
{
    public static function facts(string $line): EngineFacts;

    public function nodes(): Nodes;

    public function cluster(): Cluster;

    public function config(): Config;

    public function accounts(): Accounts;

    public function archive(): Archive;

    public function migration(): Migration;

    public function pools(): Pools;
}
