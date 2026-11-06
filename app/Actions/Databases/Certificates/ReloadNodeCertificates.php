<?php

declare(strict_types=1);

namespace App\Actions\Databases\Certificates;

use App\Models\Database;
use App\Models\DatabaseNode;

final readonly class ReloadNodeCertificates
{
    public function handle(Database $database, DatabaseNode $node): void
    {
        $database->provider()->nodes()->reloadCertificates($node);
    }
}
