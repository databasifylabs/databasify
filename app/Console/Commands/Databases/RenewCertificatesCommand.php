<?php

declare(strict_types=1);

namespace App\Console\Commands\Databases;

use App\Actions\Databases\Certificates\QueueCertificateRenewal;
use App\Models\Database;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('databases:renew-certificates')]
#[Description('Renew certificates that expire soon or no longer cover every hostname')]
final class RenewCertificatesCommand extends Command
{
    public function handle(QueueCertificateRenewal $renewal): int
    {
        $queued = 0;

        Database::query()->with('dnsZone')->lazyById()->each(function (Database $database) use ($renewal, &$queued): void {
            if ($renewal->handle($database)) {
                $queued++;
            }
        });

        $this->components->info("Queued {$queued} certificate renewals.");

        return self::SUCCESS;
    }
}
