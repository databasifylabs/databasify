<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MongoDb;

use App\Engines\Payload;

final readonly class OplogRange
{
    private const int SLACK_SECONDS = 1;

    public function __construct(public int $start, public int $end) {}

    public static function fromPayload(Payload $range): self
    {
        return new self($range->int('start'), $range->int('end'));
    }

    public function continuedBy(self $next): bool
    {
        return $next->start <= $this->end + self::SLACK_SECONDS;
    }

    public function joinedWith(self $next): self
    {
        return new self($this->start, max($this->end, $next->end));
    }

    public function startsFrom(int $snapshot): bool
    {
        return $snapshot >= $this->start - self::SLACK_SECONDS && $snapshot <= $this->end;
    }

    public function reaches(int $snapshot): bool
    {
        return $this->end + self::SLACK_SECONDS >= $snapshot;
    }
}
