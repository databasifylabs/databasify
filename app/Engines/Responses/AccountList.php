<?php

declare(strict_types=1);

namespace App\Engines\Responses;

use App\Contracts\Engines\EngineReply;
use App\Engines\Payload;

final readonly class AccountList implements EngineReply
{
    /**
     * @param  list<string>  $users
     * @param  list<string>  $databases
     */
    public function __construct(
        public array $users,
        public array $databases,
    ) {}

    public static function fromPayload(Payload $payload): static
    {
        return new self($payload->strings('users'), $payload->strings('databases'));
    }
}
