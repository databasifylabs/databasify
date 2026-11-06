<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Server;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

final readonly class HasClusterMemory implements ValidationRule
{
    public static function shortfallOf(Server $server, string $advice): string
    {
        return "{$server->name} has {$server->memory_mb} MB of memory. Every node of a database with standbys needs at least 1 GB, {$advice}.";
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $server = is_string($value) && Str::isUuid($value) ? Server::query()->find($value) : null;

        if ($server === null || $server->canJoinCluster()) {
            return;
        }

        $fail(self::shortfallOf($server, 'so it can only run a single-node database'));
    }
}
