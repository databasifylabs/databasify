<?php

declare(strict_types=1);

namespace App\Jobs\Databases;

use App\Actions\Databases\Flows\AdvanceFlow;
use App\Enums\Queues\QueueGroup;
use App\Models\DatabaseFlow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Middleware\WithoutOverlapping;

#[Queue(QueueGroup::Databases)]
#[Tries(1)]
#[Timeout(120)]
final class AdvanceDatabaseFlow implements ShouldQueue
{
    use Queueable;

    private const int LOCK_EXPIRY_SECONDS = 180;

    public function __construct(public DatabaseFlow $flow) {}

    /**
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("flow:{$this->flow->id}"))->dontRelease()->expireAfter(self::LOCK_EXPIRY_SECONDS)];
    }

    public function handle(AdvanceFlow $advance): void
    {
        $advance->handle($this->flow->fresh() ?? $this->flow);
    }
}
