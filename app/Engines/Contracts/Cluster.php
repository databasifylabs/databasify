<?php

declare(strict_types=1);

namespace App\Engines\Contracts;

use App\DTOs\Databases\QuorumCount;
use App\Engines\Data\Member;
use App\Engines\Data\Operation;

interface Cluster
{
    public function enable(string $key): Operation;

    public function disable(): void;

    public function failover(string $targetIp): void;

    public function forceQuorum(bool $othersDown): void;

    /**
     * @param  list<Member>  $members
     */
    public function membership(array $members): void;

    public function rejoin(string $key, bool $readOnly): Operation;

    public function forget(string $ip): void;

    public function position(): ?int;

    public function quorum(): QuorumCount;
}
