<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final readonly class NetworkCidr implements ValidationRule
{
    private const int SMALLEST_PREFIX = 8;

    private const int LARGEST_PREFIX = 30;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! $this->isNetworkRange($value)) {
            $fail('Enter the network range with its prefix, like 10.104.0.0/20.');
        }
    }

    private function isNetworkRange(string $value): bool
    {
        [$address, $prefix] = array_pad(explode('/', $value, 2), 2, '');

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || ! ctype_digit($prefix)) {
            return false;
        }

        $bits = (int) $prefix;

        if ($bits < self::SMALLEST_PREFIX || $bits > self::LARGEST_PREFIX) {
            return false;
        }

        $mask = -1 << (32 - $bits);

        return (ip2long($address) & $mask) === (ip2long($address) & 0xFFFFFFFF);
    }
}
