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
        if (! $database->isServing() || $database->status === DatabaseStatus::Offline || $database->isBusy() || $database->allCertificatesCurrent()) {
            return false;
        }

        $database->startOperation('Renew certificates');
        dispatch(new RenewCertificatesJob($database));

        return true;
    }
}
