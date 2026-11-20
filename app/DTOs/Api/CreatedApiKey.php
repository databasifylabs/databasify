<?php

declare(strict_types=1);

namespace App\DTOs\Api;

use App\Models\ApiKey;

final readonly class CreatedApiKey
{
    public function __construct(public ApiKey $key, public string $plainTextKey) {}
}
