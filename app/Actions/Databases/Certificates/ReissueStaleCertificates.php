<?php

declare(strict_types=1);

namespace App\Actions\Databases\Certificates;

use App\Models\Database;

final readonly class ReissueStaleCertificates
{
    public function __construct(
        private EnsureMemberCertificates $members,
        private EnsureNodeCertificates $nodes,
    ) {}

    public function handle(Database $database): bool
    {
        $membersReissued = $this->members->handle($database);

        return $this->nodes->handle($database) || $membersReissued;
    }
}
