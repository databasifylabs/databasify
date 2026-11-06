<?php

declare(strict_types=1);

namespace App\Actions\Databases\Certificates;

use App\Models\Database;

final readonly class EnsureMemberCertificates
{
    public function __construct(private IssueCertificates $issue) {}

    public function handle(Database $database): bool
    {
        if ($database->certificatesFit()) {
            return false;
        }

        $this->issue->handle($database, reuseAuthority: true);

        return true;
    }
}
