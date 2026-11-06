<?php

declare(strict_types=1);

namespace App\Actions\Databases\Domains;

use App\DTOs\Databases\ServiceRecords;
use App\Models\DatabaseNode;
use App\Services\Dns\DnsRecordPublisher;
use Illuminate\Support\Collection;

final readonly class PublishServiceRecords
{
    public function __construct(private DnsRecordPublisher $dns) {}

    /**
     * @param  Collection<int, DatabaseNode>  $nodes
     */
    public function handle(ServiceRecords $records, Collection $nodes): void
    {
        $nodes->filter(fn (DatabaseNode $node): bool => $node->isMember())
            ->each(fn (DatabaseNode $node) => $this->dns->point($records->zone, $records->memberHostOf($node), [$node->server->address()]));
        $this->dns->pointService($records->zone, $records->name, $records->targetsOf($nodes));
        $this->dns->describe($records->zone, $records->host, $records->options);
    }
}
