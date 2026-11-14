<?php

declare(strict_types=1);

namespace App\Actions\Databases\Certificates;

use App\Models\Database;
use App\Models\DatabaseNode;

final readonly class InstallNodeCertificate
{
    public function handle(Database $database, DatabaseNode $node): void
    {
        $certificate = $database->certificateFor($node);

        $node->driver()->nodes()->installTls($certificate->certificate, $certificate->key, $certificate->ca);
        $node->update(['certificate_fingerprint' => $certificate->fingerprint()]);
    }
}
