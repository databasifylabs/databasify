<?php

declare(strict_types=1);

namespace App\DTOs\Api;

use App\Enums\Api\ApiKeyExpiry;

final readonly class NewApiKey
{
    /**
     * @param  list<string>  $scopes
     * @param  list<string>|null  $projectIds
     */
    public function __construct(
        public string $name,
        public ApiKeyExpiry $expiry,
        public array $scopes,
        public ?array $projectIds,
    ) {}
}
