<?php

declare(strict_types=1);

namespace App\Engines\Contracts;

use App\DTOs\Databases\NodeStatus;
use App\Engines\Data\DefaultAccount;
use App\Engines\Data\NodeConfig;
use App\Engines\Data\NodeMetrics;
use App\Engines\Data\Operation;
use App\Engines\Data\Secrets;

interface Nodes
{
    public function configure(NodeConfig $config): void;

    public function start(): void;

    public function restart(): void;

    public function wake(bool $all): void;

    /**
     * @param  list<string>  $hosts
     */
    public function reset(int $peers, array $hosts): void;

    public function bootstrap(Secrets $secrets, ?DefaultAccount $account): void;

    public function installTls(string $certificate, string $key, string $ca): void;

    public function ready(string $key, bool $expectPrimary): Operation;

    public function status(): NodeStatus;

    public function metrics(): NodeMetrics;
}
