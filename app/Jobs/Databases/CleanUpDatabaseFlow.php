<?php

declare(strict_types=1);

namespace App\Jobs\Databases;

use App\Enums\Queues\QueueGroup;
use App\Models\DatabaseFlow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Throwable;

#[Queue(QueueGroup::Databases)]
#[Tries(1)]
#[Timeout(3600)]
final class CleanUpDatabaseFlow implements ShouldQueue
{
    use Queueable;

    public function __construct(public DatabaseFlow $flow) {}

    public function handle(): void
    {
        $database = $this->flow->database;

        try {
            $database->ignoringStop(fn () => $this->flow->definition()->cleanup($this->flow));
        } catch (Throwable $exception) {
            report($exception);
        } finally {
            $database->finishOperation();
        }
    }

    public function failed(): void
    {
        $this->flow->database->finishOperation();
    }
}
