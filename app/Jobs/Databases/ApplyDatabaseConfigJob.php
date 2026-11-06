<?php

declare(strict_types=1);

namespace App\Jobs\Databases;

use App\Actions\Databases\Configuration\ApplyDatabaseConfig;
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
#[Timeout(1800)]
final class ApplyDatabaseConfigJob implements ShouldQueue
{
    use FinishesOperation, Queueable;

    /**
     * @param  list<string>  $keys
     */
    public function __construct(public Database $database, public array $keys) {}

    public function handle(ApplyDatabaseConfig $action): void
    {
        $action->handle($this->database, $this->keys);
    }
}
