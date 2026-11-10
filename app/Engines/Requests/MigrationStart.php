<?php

declare(strict_types=1);

namespace App\Engines\Requests;

use App\Contracts\Engines\EngineRequest;
use App\Engines\Data\MigrationSource;
use App\Engines\Responses\Done;

/**
 * @implements EngineRequest<Done>
 */
final readonly class MigrationStart implements EngineRequest
{
    /**
     * @param  list<string>  $databases
     */
    public function __construct(
        public MigrationSource $source,
        public array $databases,
        public bool $continuous,
        public ?string $owner,
    ) {}

    public function command(): string
    {
        return 'migration start';
    }

    public function toPayload(): array
    {
        return ['source' => $this->source->toPayload(), 'databases' => $this->databases, 'continuous' => $this->continuous, 'owner' => $this->owner, 'publication' => null];
    }

    public function reply(): string
    {
        return Done::class;
    }
}
