<?php

declare(strict_types=1);

namespace App\DTOs\Databases;

final readonly class ProcessManifest
{
    public const int DEFAULT_STOP_SECONDS = 90;

    private const int SCHEDULES_STOP_SECONDS = self::DEFAULT_STOP_SECONDS;

    private const array LARGER_UNITS = ['h' => 3600, 'm' => 60];

    /**
     * @param  list<ProcessSpec>  $processes
     * @param  list<Schedule>  $schedules
     */
    public function __construct(
        public array $processes,
        public array $schedules = [],
    ) {}

    public static function durationOf(?int $seconds): ?string
    {
        if ($seconds === null) {
            return null;
        }

        foreach (self::LARGER_UNITS as $unit => $size) {
            if ($seconds > 0 && $seconds % $size === 0) {
                return intdiv($seconds, $size).$unit;
            }
        }

        return "{$seconds}s";
    }

    /**
     * @template TValue
     *
     * @param  array<string, TValue|null>  $fields
     * @return array<string, TValue>
     */
    public static function presentIn(array $fields): array
    {
        return array_filter($fields, fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * @return array{processes: list<array<string, string|bool|list<string>|array<string, string>>>, schedules?: list<array<string, string|list<string>>>}
     */
    public function toArray(): array
    {
        $manifest = ['processes' => array_map(fn (ProcessSpec $process): array => $process->toArray(), $this->processes)];

        if ($this->schedules === []) {
            return $manifest;
        }

        return array_merge($manifest, ['schedules' => array_map(fn (Schedule $schedule): array => $schedule->toArray(), $this->schedules)]);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(fn (ProcessSpec $process): string => $process->name, $this->processes);
    }

    public function shutdownSeconds(): int
    {
        return self::SCHEDULES_STOP_SECONDS + array_sum(array_map(fn (ProcessSpec $process): int => $process->stopSeconds(), $this->processes));
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    public function toNodeFile(string $directory): NodeFile
    {
        return NodeFile::config("{$directory}/conf/processes.json", $this->toJson());
    }
}
