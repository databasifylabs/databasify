<?php

declare(strict_types=1);

namespace App\Jobs\Databases;

use App\Actions\Databases\Upgrades\RollOutNodes;
use App\Enums\Queues\QueueGroup;
use App\Jobs\Concerns\FinishesOperation;
use App\Models\Database;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

#[Queue(QueueGroup::Databases)]
#[Tries(1)]
#[Timeout(22000)]
final class UpgradeDatabaseJob implements ShouldQueue
{
    use FinishesOperation, Queueable;

    public function __construct(public Database $database, public bool $installUpdate) {}

    public function handle(RollOutNodes $action): void
    {
        $action->handle($this->database, $this->installUpdate);
    }
}
