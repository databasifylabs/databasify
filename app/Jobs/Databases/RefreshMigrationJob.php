<?php

declare(strict_types=1);

namespace App\Jobs\Databases;

use App\Actions\Databases\Migrations\RefreshMigration;
use App\Enums\Queues\QueueGroup;
use App\Models\DatabaseMigration;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;

#[Queue(QueueGroup::Monitoring)]
#[Tries(1)]
#[Timeout(900)]
#[UniqueFor(1200)]
final class RefreshMigrationJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public DatabaseMigration $migration) {}

    public function uniqueId(): string
    {
        return (string) $this->migration->id;
    }

    public function handle(RefreshMigration $action): void
    {
        $action->handle($this->migration);
    }
}
