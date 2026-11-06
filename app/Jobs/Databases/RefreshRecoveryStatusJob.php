<?php

declare(strict_types=1);

namespace App\Jobs\Databases;

use App\Actions\Databases\Recovery\RefreshRecoveryStatus;
use App\Enums\Queues\QueueGroup;
use App\Models\PointInTimeRecovery;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\FailOnTimeout;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;

#[Queue(QueueGroup::Monitoring)]
#[Tries(20)]
#[MaxExceptions(1)]
#[FailOnTimeout]
#[Timeout(300)]
#[UniqueFor(1200)]
final class RefreshRecoveryStatusJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    private const int BUSY_RETRY_SECONDS = 30;

    public function __construct(public PointInTimeRecovery $recovery, public bool $backUpNow = false) {}

    public function uniqueId(): string
    {
        return $this->backUpNow ? "{$this->recovery->id}:backup" : (string) $this->recovery->id;
    }

    public function handle(RefreshRecoveryStatus $refresh): void
    {
        if ($this->recovery->database->isBusy()) {
            $this->retryBackUpLater();

            return;
        }

        $refresh->handle($this->recovery, $this->backUpNow);
    }

    private function retryBackUpLater(): void
    {
        if (! $this->backUpNow) {
            return;
        }

        $this->release(self::BUSY_RETRY_SECONDS);
    }
}
