<?php

declare(strict_types=1);

namespace App\Jobs\Databases;

use App\Actions\Databases\Extensions\ApplyExtensionPackageUpdate;
use App\Actions\Databases\Lifecycle\EndFailedOperation;
use App\DTOs\Databases\PackageRequest;
use App\Enums\Queues\QueueGroup;
use App\Models\Database;
use App\Support\Databases\ExtensionChanges;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Storage;
use Throwable;

#[Queue(QueueGroup::Databases)]
#[Tries(1)]
#[Timeout(3600)]
final class UpdateExtensionPackageJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Database $database,
        public string $packageId,
        public PackageRequest $request,
        public string $run,
    ) {}

    public function handle(ApplyExtensionPackageUpdate $action): void
    {
        $action->handle($this->database, $this->packageId, $this->request, $this->run);
    }

    public function failed(?Throwable $exception): void
    {
        Storage::disk('local')->delete($this->request->uploads);
        resolve(ExtensionChanges::class)->end($this->database);
        resolve(EndFailedOperation::class)->handle($this->database, 'Update extension package', $exception);
    }
}
