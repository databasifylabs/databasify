<?php

declare(strict_types=1);

namespace App\Jobs\Databases;

use App\Actions\Databases\Certificates\RenewCertificates;
use App\Actions\Databases\Lifecycle\EndFailedOperation;
use App\Enums\Queues\QueueGroup;
use App\Models\Database;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Throwable;

#[Queue(QueueGroup::Databases)]
#[Tries(1)]
#[Timeout(3600)]
final class RenewCertificatesJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public Database $database) {}

    public function handle(RenewCertificates $action): void
    {
        $action->handle($this->database);
    }

    public function failed(?Throwable $exception): void
    {
        resolve(EndFailedOperation::class)->handle($this->database, 'Renew certificates', $exception);
    }
}
