<?php

declare(strict_types=1);

namespace App\Engines\Data;

use App\Engines\Payload;

final readonly class AccountList
{
    /**
     * @param  list<string>  $users
     * @param  list<string>  $databases
     */
    public function __construct(
        public array $users,
        public array $databases,
    ) {}

    public static function fromPayload(Payload $payload): self
    {
        return new self($payload->strings('users'), $payload->strings('databases'));
    }
}
