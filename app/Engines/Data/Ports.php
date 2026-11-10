<?php

declare(strict_types=1);

namespace App\Engines\Data;

use App\Engines\Payload;

final readonly class Ports
{
    /**
     * @param  list<int>  $peers
     */
    public function __construct(
        public int $client,
        public ?int $pool,
        public array $peers,
    ) {}

    public static function fromPayload(Payload $payload): self
    {
        return new self($payload->int('client'), $payload->nullableInt('pool'), $payload->ints('peers'));
    }

    /**
     * @return array{client: int, pool: ?int, peers: list<int>}
     */
    public function toArray(): array
    {
        return ['client' => $this->client, 'pool' => $this->pool, 'peers' => $this->peers];
    }
}
