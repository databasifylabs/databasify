<?php

declare(strict_types=1);

namespace App\Engines\Requests;

use App\Contracts\Engines\EngineRequest;
use App\Engines\Responses\Done;

/**
 * @implements EngineRequest<Done>
 */
final readonly class UserSetPassword implements EngineRequest
{
    public function __construct(
        public string $name,
        public string $password,
        public ?string $encryption,
    ) {}

    public function command(): string
    {
        return 'user set-password';
    }

    public function toPayload(): array
    {
        return ['name' => $this->name, 'password' => $this->password, 'encryption' => $this->encryption];
    }

    public function reply(): string
    {
        return Done::class;
    }
}
