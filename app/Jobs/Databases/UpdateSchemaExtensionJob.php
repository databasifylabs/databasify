<?php

declare(strict_types=1);

namespace App\Jobs\Databases;

use App\Actions\Databases\Extensions\ApplySchemaExtensionUpdate;
use App\Actions\Databases\Lifecycle\EndFailedOperation;
use App\Enums\Queues\QueueGroup;
use App\Models\Database;
use App\Support\Databases\ExtensionChanges;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Throwable;

#[Queue(QueueGroup::Databases)]
#[Tries(1)]
#[Timeout(600)]
final class UpdateSchemaExtensionJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Database $database,
        public string $schema,
        public string $name,
    ) {}

    public function handle(ApplySchemaExtensionUpdate $action): void
    {
        $action->handle($this->database, $this->schema, $this->name);
    }

    public function failed(?Throwable $exception): void
    {
        resolve(ExtensionChanges::class)->end($this->database);
        resolve(EndFailedOperation::class)->handle($this->database, 'Update extension', $exception);
    }
}
