<?php

declare(strict_types=1);

namespace App\Jobs\Databases;

use App\Actions\Databases\Upgrades\ApplyMaintenance;
use App\Enums\Queues\QueueGroup;
use App\Models\Database;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

#[Queue(QueueGroup::Databases)]
#[Tries(1)]
#[Timeout(120)]
final class ApplyMaintenanceJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public Database $database) {}

    public function handle(ApplyMaintenance $action): void
    {
        $action->handle($this->database);
    }
}
