<?php

declare(strict_types=1);

namespace App\DTOs\Updates;

use App\Enums\Updates\UpdateState;
use App\Enums\Updates\UpdateStepState;
use App\Services\Http\Payload;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;

final readonly class UpdateStatus
{
    /**
     * @param  list<UpdateStep>  $steps
     */
    public function __construct(
        public UpdateState $state,
        public string $from,
        public string $to,
        public CarbonImmutable $startedAt,
        public ?CarbonImmutable $finishedAt,
        public bool $rolledBack,
        public string $message,
        public array $steps,
    ) {}

    public static function fromJson(string $json): ?self
    {
        $payload = Payload::of(json_decode($json, true));
        $state = UpdateState::tryFrom($payload->string('state'));
        $startedAt = $payload->stringOrNull('started_at');

        if ($state === null || $startedAt === null) {
            return null;
        }

        $finishedAt = $payload->stringOrNull('finished_at');

        try {
            $startedAt = CarbonImmutable::parse($startedAt);
            $finishedAt = $finishedAt === null ? null : CarbonImmutable::parse($finishedAt);
        } catch (InvalidFormatException) {
            return null;
        }

        return new self(
            $state,
            $payload->string('from'),
            $payload->string('to'),
            $startedAt,
            $finishedAt,
            $payload->bool('rolled_back'),
            $payload->string('message'),
            self::stepsIn($payload),
        );
    }

    /**
     * @return array{state: string, from: string, to: string, startedAt: string, finishedAt: string|null, rolledBack: bool, message: string, steps: list<array{title: string, state: string}>}
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            'from' => $this->from,
            'to' => $this->to,
            'startedAt' => $this->startedAt->toIso8601ZuluString(),
            'finishedAt' => $this->finishedAt?->toIso8601ZuluString(),
            'rolledBack' => $this->rolledBack,
            'message' => $this->message,
            'steps' => array_map(fn (UpdateStep $step): array => ['title' => $step->title, 'state' => $step->state->value], $this->steps),
        ];
    }

    public function isFailed(): bool
    {
        return $this->state === UpdateState::Failed;
    }

    public function isRunning(): bool
    {
        return $this->state === UpdateState::Running;
    }

    public function failedStep(): ?UpdateStep
    {
        return array_find($this->steps, fn (UpdateStep $step): bool => $step->state === UpdateStepState::Failed);
    }

    /**
     * @return list<UpdateStep>
     */
    private static function stepsIn(Payload $payload): array
    {
        return array_values(array_filter(array_map(UpdateStep::fromPayload(...), $payload->items('steps'))));
    }
}
