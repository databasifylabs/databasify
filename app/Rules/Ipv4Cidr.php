<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final readonly class Ipv4Cidr implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! $this->isCidr($value)) {
            $fail('Enter an IPv4 address or CIDR range, like 203.0.113.7 or 203.0.113.0/24.');
        }
    }

    private function isCidr(string $value): bool
    {
        [$address, $prefix] = array_pad(explode('/', $value, 2), 2, '32');

        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && ctype_digit($prefix)
            && (int) $prefix >= 1
            && (int) $prefix <= 32;
    }
}
