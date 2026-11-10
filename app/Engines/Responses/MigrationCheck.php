<?php

declare(strict_types=1);

namespace App\Engines\Responses;

use App\Contracts\Engines\EngineReply;
use App\Engines\Payload;

final readonly class MigrationCheck implements EngineReply
{
    /**
     * @param  list<string>  $databases
     * @param  list<string>  $unreadable
     * @param  list<string>  $unowned
     * @param  list<string>  $occupied
     * @param  list<string>  $warnings
     */
    public function __construct(
        public bool $reachable,
        public ?string $error,
        public ?string $version,
        public ?string $walLevel,
        public bool $canReplicate,
        public ?int $sizeMb,
        public array $databases,
        public array $unreadable,
        public array $unowned,
        public array $occupied,
        public array $warnings,
    ) {}

    public static function fromPayload(Payload $payload): static
    {
        return new self(
            reachable: $payload->bool('reachable'),
            error: $payload->nullableString('error'),
            version: $payload->nullableString('version'),
            walLevel: $payload->nullableString('walLevel'),
            canReplicate: $payload->bool('canReplicate'),
            sizeMb: $payload->nullableInt('sizeMb'),
            databases: $payload->strings('databases'),
            unreadable: $payload->strings('unreadable'),
            unowned: $payload->strings('unowned'),
            occupied: $payload->strings('occupied'),
            warnings: $payload->strings('warnings'),
        );
    }
}
