<?php

declare(strict_types=1);

namespace App\Actions\Databases\Certificates;

use App\Enums\Databases\DatabaseStatus;
use App\Jobs\Databases\RenewCertificatesJob;
use App\Models\Database;

final readonly class QueueCertificateRenewal
{
    public function handle(Database $database): bool
    {
        if ($database->status !== DatabaseStatus::Ready || ! $database->nodesWithServers()->anyServes() || $database->allCertificatesCurrent()) {
            return false;
        }

        $database->markBusy(DatabaseStatus::Updating);
        dispatch(new RenewCertificatesJob($database));

        return true;
    }
}
