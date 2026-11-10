<?php

declare(strict_types=1);

namespace App\Engines\Requests;

use App\Contracts\Engines\EngineRequest;
use App\Engines\Data\Member;
use App\Engines\Responses\Done;

/**
 * @implements EngineRequest<Done>
 */
final readonly class ClusterMembership implements EngineRequest
{
    /**
     * @param  list<Member>  $members
     */
    public function __construct(public array $members) {}

    public function command(): string
    {
        return 'cluster membership';
    }

    public function toPayload(): array
    {
        return ['members' => array_map(fn (Member $member): array => $member->toPayload(), $this->members)];
    }

    public function reply(): string
    {
        return Done::class;
    }
}
