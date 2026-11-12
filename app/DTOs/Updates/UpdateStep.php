<?php

declare(strict_types=1);

namespace App\DTOs\Updates;

use App\Enums\Updates\UpdateStepState;
use App\Services\Http\Payload;

final readonly class UpdateStep
{
    public function __construct(public string $title, public UpdateStepState $state) {}

    public static function fromPayload(Payload $payload): ?self
    {
        $state = UpdateStepState::tryFrom($payload->string('state'));
        $title = $payload->string('title');

        return $state === null || $title === '' ? null : new self($title, $state);
    }
}
