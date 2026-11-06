<?php

declare(strict_types=1);

namespace App\DTOs\Databases;

final readonly class Schedule
{
    /**
     * @param  list<string>  $argv
     */
    public function __construct(
        public string $name,
        public array $argv,
        public ?int $everySeconds = null,
        public ?string $dailyAt = null,
        public ?string $user = null,
        public ?int $timeoutSeconds = null,
    ) {}

    /**
     * @param  list<string>  $argv
     */
    public static function every(string $name, int $seconds, array $argv, ?string $user = null): self
    {
        return new self($name, $argv, everySeconds: $seconds, user: $user);
    }

    /**
     * @param  list<string>  $argv
     */
    public static function dailyAt(string $name, string $time, array $argv, ?string $user = null): self
    {
        return new self($name, $argv, dailyAt: $time, user: $user);
    }

    public function timingOutAfter(int $seconds): self
    {
        return clone ($this, ['timeoutSeconds' => $seconds]);
    }

    /**
     * @return array<string, string|list<string>>
     */
    public function toArray(): array
    {
        return ProcessManifest::presentIn([
            'name' => $this->name,
            'every' => ProcessManifest::durationOf($this->everySeconds),
            'onCalendar' => $this->dailyAt,
            'user' => $this->user,
            'timeout' => ProcessManifest::durationOf($this->timeoutSeconds),
            'argv' => $this->argv,
        ]);
    }
}
