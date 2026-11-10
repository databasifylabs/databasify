<?php

declare(strict_types=1);

namespace App\Actions\Databases\Domains;

use App\DTOs\Databases\ServiceRecords;
use App\Enums\Dns\DnsRecordType;
use App\Models\DatabaseNode;
use App\Services\Dns\DnsRecordPublisher;
use Illuminate\Support\Collection;

final readonly class WithdrawServiceRecords
{
    public function __construct(
        private DnsRecordPublisher $dns,
        private ClearHostnames $clear,
    ) {}

    /**
     * @param  Collection<int, DatabaseNode>  $nodes
     */
    public function handle(ServiceRecords $records, Collection $nodes): void
    {
        $this->clear->handle($records->zone, $records->memberHostsOf($nodes));
        $this->dns->clear($records->zone, DnsRecordType::Srv, $records->name);
        $this->dns->clear($records->zone, DnsRecordType::Txt, $records->host);
    }
}
