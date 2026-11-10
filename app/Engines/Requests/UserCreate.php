<?php

declare(strict_types=1);

namespace App\Engines\Requests;

use App\Contracts\Engines\EngineRequest;
use App\Engines\Data\Grant;
use App\Engines\Responses\UserCreate as UserCreateReply;

/**
 * @implements EngineRequest<UserCreateReply>
 */
final readonly class UserCreate implements EngineRequest
{
    /**
     * @param  list<Grant>  $grants
     */
    public function __construct(
        public string $name,
        public string $password,
        public array $grants,
        public ?string $encryption,
    ) {}

    public function command(): string
    {
        return 'user create';
    }

    public function toPayload(): array
    {
        return [
            'name' => $this->name,
            'password' => $this->password,
            'grants' => array_map(fn (Grant $grant): array => $grant->toPayload(), $this->grants),
            'encryption' => $this->encryption,
        ];
    }

    public function reply(): string
    {
        return UserCreateReply::class;
    }
}
