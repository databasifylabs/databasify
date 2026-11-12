<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MySqlFamily;

use App\Engines\Responses\NodeMetrics;
use App\Engines\Responses\NodeStatus;
use App\Enums\Databases\NodeRole;
use App\Models\DatabaseNode;

final readonly class Readings
{
    public function __construct(private FamilyScripts $scripts) {}

    public function status(DatabaseNode $node): NodeStatus
    {
        $reply = $this->scripts->run($node, '{engine}/nodes/status')->json();

        return new NodeStatus(NodeRole::from($reply->string('role')), $reply->nullableString('version'));
    }

    public function metrics(DatabaseNode $node): NodeMetrics
    {
        return NodeMetrics::fromPayload($this->scripts->run($node, 'mysql-family/nodes/metrics')->json());
    }
}
