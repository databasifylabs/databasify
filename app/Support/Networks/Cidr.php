<?php

declare(strict_types=1);

namespace App\Support\Networks;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\IpUtils;

final class Cidr
{
    private const int IPV4_BITS = 32;

    /**
     * @phpstan-assert-if-true string $value
     */
    public static function isValid(mixed $value): bool
    {
        if (! is_string($value) || preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})/(\d{1,2})$#', $value, $parts) !== 1) {
            return false;
        }

        return filter_var($parts[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && (int) $parts[2] <= self::IPV4_BITS;
    }

    public static function toLong(string $address): int
    {
        $long = ip2long($address);

        if ($long === false) {
            throw new InvalidArgumentException("{$address} is not an IPv4 address.");
        }

        return $long;
    }

    public static function prefix(string $cidr): int
    {
        return (int) Str::after($cidr, '/');
    }

    public static function size(int $prefix): int
    {
        return 2 ** (self::IPV4_BITS - $prefix);
    }

    public static function start(string $cidr): int
    {
        return self::toLong(self::network($cidr));
    }

    public static function length(string $cidr): int
    {
        return self::size(self::prefix($cidr));
    }

    public static function network(string $cidr): string
    {
        $address = self::toLong(Str::before($cidr, '/'));

        return long2ip($address - $address % self::length($cidr));
    }

    public static function canonical(string $cidr): string
    {
        return self::network($cidr).'/'.self::prefix($cidr);
    }

    public static function overlaps(string $first, string $second): bool
    {
        return IpUtils::checkIp4(self::network($first), $second) || IpUtils::checkIp4(self::network($second), $first);
    }
}
