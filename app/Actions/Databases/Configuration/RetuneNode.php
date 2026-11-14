<?php

declare(strict_types=1);

namespace App\Actions\Databases\Configuration;

use App\Enums\Databases\DatabaseStatus;
use App\Jobs\Databases\RetuneNodeJob;
use App\Models\DatabaseNode;

final readonly class RetuneNode
{
    public function handle(DatabaseNode $node): void
    {
        $node->ensureReadOnly();
        $node->database->ensureRunsSupportedSdk();
        $node->markBusy(DatabaseStatus::Updating);
        dispatch(new RetuneNodeJob($node));
    }
}
