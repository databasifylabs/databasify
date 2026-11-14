<?php

declare(strict_types=1);

namespace App\Jobs\Networks;

use App\Actions\Networks\SyncWireguardNetwork;
use App\Enums\Queues\QueueGroup;
use App\Models\Network;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\FailOnTimeout;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

#[Queue(QueueGroup::Provisioning)]
#[Tries(60)]
#[MaxExceptions(1)]
#[FailOnTimeout]
#[Timeout(900)]
#[UniqueFor(900)]
final class SyncWireguardNetworkJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    private const int WAIT_SECONDS = 15;

    private const int LOCK_EXPIRY_SECONDS = 900;

    public function __construct(public Network $network) {}

    public function uniqueId(): string
    {
        return $this->network->id;
    }

    /**
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->network->id))->releaseAfter(self::WAIT_SECONDS)->expireAfter(self::LOCK_EXPIRY_SECONDS)];
    }

    public function handle(SyncWireguardNetwork $sync): void
    {
        $sync->handle($this->network);
    }

    public function failed(?Throwable $exception): void
    {
        $this->network->failRunningLogs([SyncWireguardNetwork::LOG_NAME], $exception);
    }
}
