<?php

declare(strict_types=1);

namespace App\Jobs\Networks;

use App\Actions\Networks\CheckWireguardNetwork;
use App\Enums\Queues\QueueGroup;
use App\Models\Network;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;

#[Queue(QueueGroup::Monitoring)]
#[Tries(1)]
#[Timeout(600)]
#[UniqueFor(900)]
final class CheckWireguardNetworkJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public Network $network) {}

    public function uniqueId(): string
    {
        return $this->network->id;
    }

    public function handle(CheckWireguardNetwork $check): void
    {
        $check->handle($this->network);
    }
}
