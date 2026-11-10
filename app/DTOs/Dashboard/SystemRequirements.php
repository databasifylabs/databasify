<?php

declare(strict_types=1);

namespace App\DTOs\Dashboard;

use App\Engines\Engine;
use App\Engines\EngineCatalog;
use App\Enums\Servers\OperatingSystem;

final readonly class SystemRequirements
{
    private function __construct(
        public string $systems,
        public string $engines,
    ) {}

    public static function current(): self
    {
        return new self(
            systems: OperatingSystem::supportedSummary(),
            engines: EngineCatalog::all()->map(fn (Engine $engine): string => $engine->label)->join(', ', ' or '),
        );
    }

    /**
     * @return array{systems: string, engines: string}
     */
    public function toArray(): array
    {
        return ['systems' => $this->systems, 'engines' => $this->engines];
    }
}
