<?php

declare(strict_types=1);

namespace App\DTOs\Migrations;

use App\Engines\Responses\MigrationCheck;

final readonly class SourceReport
{
    /**
     * @param  list<string>  $blockers
     * @param  array<string, string>  $facts
     * @param  list<string>  $occupied
     * @param  list<string>  $problems
     * @param  list<string>  $warnings
     * @param  list<string>  $databases
     */
    public function __construct(
        public bool $reachable,
        public array $blockers = [],
        public array $facts = [],
        public ?string $error = null,
        public array $occupied = [],
        public array $problems = [],
        public array $warnings = [],
        public array $databases = [],
    ) {}

    public static function fromEngine(MigrationCheck $check): self
    {
        if (! $check->reachable) {
            return self::unreachable($check->error ?? 'The source did not answer.');
        }

        return new self(
            reachable: true,
            blockers: $check->canReplicate ? [] : ['The source cannot replicate continuously, so only a one-time copy is possible.'],
            facts: array_filter(['version' => $check->version ?? '', 'size_mb' => (string) ($check->sizeMb ?? 0), 'wal_level' => $check->walLevel ?? ''], fn (string $fact): bool => $fact !== ''),
            occupied: $check->occupied,
            problems: [
                ...array_map(fn (string $database): string => "Databasify cannot read {$database} on the source.", $check->unreadable),
                ...array_map(fn (string $database): string => "{$database} is not owned by the source user.", $check->unowned),
                ...$check->problems,
            ],
            warnings: $check->warnings,
            databases: $check->databases,
        );
    }

    public static function unreachable(string $error): self
    {
        return new self(false, error: $error);
    }

    public function supportsContinuous(): bool
    {
        return $this->reachable && $this->blockers === [];
    }

    public function fact(string $name): string
    {
        return $this->facts[$name] ?? '';
    }

    /**
     * @return array{reachable: bool, continuous: bool, blockers: list<string>, problems: list<string>, warnings: list<string>, facts: array<string, string>, error: ?string}
     */
    public function toArray(): array
    {
        return [
            'reachable' => $this->reachable,
            'continuous' => $this->supportsContinuous(),
            'blockers' => $this->blockers,
            'problems' => $this->problems,
            'warnings' => $this->warnings,
            'facts' => $this->facts,
            'error' => $this->error,
        ];
    }
}
