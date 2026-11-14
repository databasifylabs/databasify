<?php

declare(strict_types=1);

namespace App\Actions\Databases\Network;

use App\Enums\Databases\DatabaseStatus;
use App\Jobs\Databases\ApplyTrustedSourcesJob;
use App\Models\Database;

final readonly class QueueTrustedSources
{
    public function handle(Database $database): void
    {
        $database->markBusy(DatabaseStatus::Updating);
        dispatch(new ApplyTrustedSourcesJob($database));
    }
}
