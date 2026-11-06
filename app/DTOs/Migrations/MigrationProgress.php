<?php

declare(strict_types=1);

namespace App\DTOs\Migrations;

use App\Enums\Migrations\MigrationPhase;

final readonly class MigrationProgress
{
    public function __construct(
        public MigrationPhase $phase,
        public string $detail,
        public ?int $lagSeconds = null,
    ) {}

    public static function working(string $detail): self
    {
        return new self(MigrationPhase::Working, $detail);
    }

    public static function caughtUp(string $detail, ?int $lagSeconds = null): self
    {
        return new self(MigrationPhase::CaughtUp, $detail, $lagSeconds);
    }

    public static function stalled(string $detail): self
    {
        return new self(MigrationPhase::Stalled, $detail);
    }

    public static function broken(string $detail): self
    {
        return new self(MigrationPhase::Broken, $detail);
    }
}
