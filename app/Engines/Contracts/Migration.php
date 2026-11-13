<?php

declare(strict_types=1);

namespace App\Engines\Contracts;

use App\DTOs\Migrations\MigrationProgress;
use App\DTOs\Migrations\SourceReport;
use App\Engines\Data\MigrationSource;
use App\Engines\Data\MigrationStart;

interface Migration
{
    /**
     * @param  list<string>  $databases
     */
    public function check(MigrationSource $source, array $databases): SourceReport;

    public function start(MigrationStart $start): void;

    public function progress(): MigrationProgress;

    public function stop(): void;
}
