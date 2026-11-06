<?php

declare(strict_types=1);

namespace App\Jobs\Databases;

use App\Actions\Databases\Certificates\RenewCertificates;
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
#[Timeout(3600)]
final class RenewCertificatesJob implements ShouldQueue
{
    use FinishesOperation, Queueable;

    public function __construct(public Database $database) {}

    public function handle(RenewCertificates $action): void
    {
        $action->handle($this->database);
    }
}
