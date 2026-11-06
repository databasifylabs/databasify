<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Server;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

final readonly class SharesPrivateNetwork implements ValidationRule
{
    public function __construct(private ?Server $anchor) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->anchor === null) {
            return;
        }

        $network = $this->anchor->network;

        if ($network === null) {
            $fail("{$this->anchor->name} has no private network. Standby and read-only nodes need one shared with it.");

            return;
        }

        $server = is_string($value) && Str::isUuid($value) ? Server::query()->find($value) : null;

        if ($server === null || $this->anchor->sharesPrivateNetworkWith($server)) {
            return;
        }

        $fail("{$server->name} is not on {$this->anchor->name}'s private network {$network->name} ({$network->cidr}).");
    }
}
