<?php

declare(strict_types=1);

namespace App\DTOs\Api;

final readonly class AccessGrant
{
    /**
     * @param  list<non-empty-string>  $scopes
     * @param  list<string>|null  $projectIds
     */
    public function __construct(
        public array $scopes,
        public ?array $projectIds,
    ) {}
}
