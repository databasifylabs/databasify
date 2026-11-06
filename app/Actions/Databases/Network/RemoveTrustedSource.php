<?php

declare(strict_types=1);

namespace App\Actions\Databases\Network;

use App\Models\TrustedSource;

final readonly class RemoveTrustedSource
{
    public function __construct(private QueueTrustedSources $queue) {}

    public function handle(TrustedSource $source): void
    {
        $database = $source->database;
        $database->ensureIdle();
        $source->delete();

        $this->queue->handle($database, "Remove trusted source {$source->cidr}");
    }
}
