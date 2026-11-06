<?php

declare(strict_types=1);

namespace App\Actions\Databases\Domains;

use App\Models\DatabaseNode;
use App\Services\Dns\DnsRecordPublisher;

final readonly class ClearNodeDns
{
    public function __construct(
        private DnsRecordPublisher $dns,
        private ClearHostnames $clear,
    ) {}

    public function handle(DatabaseNode $node): void
    {
        $this->clearMemberHostOf($node);
        $zone = $node->database->dnsZone;
        $hostnames = $node->hostnames();

        if ($zone === null || $hostnames === null) {
            return;
        }

        $this->clear->handle($zone, $hostnames->all());
    }

    private function clearMemberHostOf(DatabaseNode $node): void
    {
        $records = $node->database->serviceRecords();

        if ($records !== null && $node->isMember()) {
            $this->dns->point($records->zone, $records->memberHostOf($node), []);
        }
    }
}
