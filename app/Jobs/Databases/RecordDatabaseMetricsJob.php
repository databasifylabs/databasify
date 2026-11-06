<?php

declare(strict_types=1);

namespace App\Jobs\Databases;

use App\Actions\Databases\Monitoring\EvaluateResourceAlerts;
use App\Actions\Databases\Monitoring\RecordDatabaseMetrics;
use App\Enums\Queues\QueueGroup;
use App\Models\Database;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;

#[Queue(QueueGroup::Monitoring)]
#[Tries(1)]
#[Timeout(300)]
#[UniqueFor(600)]
final class RecordDatabaseMetricsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public Database $database) {}

    public function uniqueId(): string
    {
        return (string) $this->database->id;
    }

    public function handle(RecordDatabaseMetrics $record, EvaluateResourceAlerts $alerts): void
    {
        $record->handle($this->database);
        $alerts->handle($this->database);
    }
}
