<?php

declare(strict_types=1);

namespace App\Contracts\Engines;

use App\Engines\Flows\FlowContext;
use App\Engines\Flows\Step;
use App\Models\Database;
use App\Models\DatabaseFlow;

interface Flow
{
    public function name(Database $database, FlowContext $context): string;

    /**
     * @return list<Step>
     */
    public function steps(DatabaseFlow $flow): array;

    public function cleanup(DatabaseFlow $flow): void;
}
