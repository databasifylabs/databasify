<?php

declare(strict_types=1);

namespace App\Actions\Databases\Network;

use App\Jobs\Databases\ApplyTrustedSourcesJob;
use App\Models\Database;

final readonly class QueueTrustedSources
{
    public function handle(Database $database, string $title): void
    {
        $database->startOperation($title);
        dispatch(new ApplyTrustedSourcesJob($database));
    }
}
