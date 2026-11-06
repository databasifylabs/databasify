<?php

declare(strict_types=1);

namespace App\Actions\Databases\Certificates;

use App\Models\Database;
use App\Models\DatabaseNode;

final readonly class EnsureNodeCertificates
{
    public function __construct(private IssueNodeCertificate $issue) {}

    public function handle(Database $database): bool
    {
        $stale = $database->readOnlyNodes()->reject(fn (DatabaseNode $node): bool => $node->certificateFits());
        $stale->each(fn (DatabaseNode $node) => $this->issue->handle($database, $node));

        return $stale->isNotEmpty();
    }
}
