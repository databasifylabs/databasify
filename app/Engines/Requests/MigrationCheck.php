<?php

declare(strict_types=1);

namespace App\Engines\Requests;

use App\Contracts\Engines\EngineRequest;
use App\Engines\Data\MigrationSource;
use App\Engines\Responses\MigrationCheck as MigrationCheckReply;

/**
 * @implements EngineRequest<MigrationCheckReply>
 */
final readonly class MigrationCheck implements EngineRequest
{
    /**
     * @param  list<string>  $databases
     */
    public function __construct(
        public MigrationSource $source,
        public array $databases,
    ) {}

    public function command(): string
    {
        return 'migration check';
    }

    public function toPayload(): array
    {
        return ['source' => $this->source->toPayload(), 'databases' => $this->databases];
    }

    public function reply(): string
    {
        return MigrationCheckReply::class;
    }
}
