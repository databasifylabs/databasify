<?php

declare(strict_types=1);

namespace App\Jobs\Databases;

use App\Actions\Databases\Extensions\PrepareExtensionPackages;
use App\DTOs\Databases\EngineImage;
use App\Enums\Queues\QueueGroup;
use App\Models\Database;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

#[Queue(QueueGroup::Databases)]
#[Tries(1)]
#[Timeout(1800)]
final class PrepareExtensionPackagesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(
        public Database $database,
        public EngineImage $image,
    ) {}

    public int $uniqueFor = 1800;

    public function uniqueId(): string
    {
        return "{$this->database->id}:{$this->image->digest}";
    }

    public function handle(PrepareExtensionPackages $action): void
    {
        $action->handle($this->database, $this->image);
    }
}
