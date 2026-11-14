<?php

declare(strict_types=1);

namespace App\Jobs\Databases;

use App\Actions\Databases\Lifecycle\EndFailedOperation;
use App\Actions\Databases\Network\ApplyTrustedSources;
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
#[Timeout(600)]
final class ApplyTrustedSourcesJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public Database $database) {}

    public function handle(ApplyTrustedSources $action): void
    {
        $action->handle($this->database);
    }

    public function failed(?Throwable $exception): void
    {
        resolve(EndFailedOperation::class)->handle($this->database, 'Apply trusted sources', $exception);
    }
}
