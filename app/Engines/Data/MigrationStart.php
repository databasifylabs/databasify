<?php

declare(strict_types=1);

namespace App\Engines\Data;

final readonly class MigrationStart
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
}
