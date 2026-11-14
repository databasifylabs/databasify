<?php

declare(strict_types=1);

namespace App\Actions\Databases\Network;

use App\Models\DatabaseNode;

final readonly class ApplyNodeTrustedSources
{
    public function __construct(private ApplyNodeFirewall $firewall) {}

    public function handle(DatabaseNode $node): void
    {
        $this->firewall->handle($node->database, $node, $node->trustedCidrs());
        $node->markReady();
    }
}
