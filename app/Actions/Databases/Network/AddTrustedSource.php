<?php

declare(strict_types=1);

namespace App\Actions\Databases\Network;

use App\DTOs\Databases\NewTrustedSource;
use App\Models\Database;

final readonly class AddTrustedSource
{
    public function __construct(private QueueTrustedSources $queue) {}

    public function handle(Database $database, NewTrustedSource $data): void
    {
        $database->ensureIdle();
        $database->trustedSources()->firstOrCreate(['cidr' => $data->cidr], ['label' => $data->label]);

        $this->queue->handle($database, "Allow {$data->cidr}");
    }
}
