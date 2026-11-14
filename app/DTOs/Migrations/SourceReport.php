<?php

declare(strict_types=1);

namespace App\DTOs\Migrations;

use App\Engines\Payload;

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

    public static function fromPayload(Payload $payload): self
    {
        if (! $payload->bool('reachable')) {
            return self::unreachable($payload->nullableString('error') ?? 'The source did not answer.');
        }

        return self::checked(
            version: $payload->nullableString('version'),
            canReplicate: $payload->bool('canReplicate'),
            sizeMb: $payload->nullableInt('sizeMb'),
            databases: $payload->strings('databases'),
            unreadable: $payload->strings('unreadable'),
            unowned: $payload->strings('unowned'),
            occupied: $payload->strings('occupied'),
            warnings: $payload->strings('warnings'),
            problems: $payload->strings('problems'),
            walLevel: $payload->nullableString('walLevel'),
        );
    }

    /**
     * @param  list<string>  $databases
     * @param  list<string>  $unreadable
     * @param  list<string>  $unowned
     * @param  list<string>  $occupied
     * @param  list<string>  $warnings
     * @param  list<string>  $problems
     */
    public static function checked(?string $version, bool $canReplicate, ?int $sizeMb, array $databases, array $unreadable = [], array $unowned = [], array $occupied = [], array $warnings = [], array $problems = [], ?string $walLevel = null): self
    {
        return new self(
            reachable: true,
            blockers: $canReplicate ? [] : ['The source cannot replicate continuously, so only a one-time copy is possible.'],
            facts: array_filter(['version' => $version ?? '', 'size_mb' => (string) ($sizeMb ?? 0), 'wal_level' => $walLevel ?? ''], fn (string $fact): bool => $fact !== ''),
            occupied: $occupied,
            problems: [
                ...array_map(fn (string $database): string => "Databasify cannot read {$database} on the source.", $unreadable),
                ...array_map(fn (string $database): string => "{$database} is not owned by the source user.", $unowned),
                ...$problems,
            ],
            warnings: $warnings,
            databases: $databases,
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
