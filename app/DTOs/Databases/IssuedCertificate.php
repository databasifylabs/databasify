<?php

declare(strict_types=1);

namespace App\DTOs\Databases;

use Carbon\CarbonImmutable;

final readonly class IssuedCertificate
{
    private const int RENEW_DAYS = 30;

    private const array NAME_PREFIXES = ['DNS:', 'IP Address:'];

    /**
     * @param  list<string>  $names
     */
    public function __construct(
        public array $names,
        public CarbonImmutable $expiresAt,
        public bool $rsaKey = false,
    ) {}

    public static function fromPem(string $pem): ?self
    {
        $facts = $pem === '' ? false : openssl_x509_parse($pem);

        if ($facts === false || ! is_int($facts['validTo_time_t'] ?? null)) {
            return null;
        }

        $extensions = is_array($facts['extensions'] ?? null) ? $facts['extensions'] : [];
        $alternatives = is_string($extensions['subjectAltName'] ?? null) ? $extensions['subjectAltName'] : '';

        return new self(self::namesIn($alternatives), CarbonImmutable::createFromTimestamp($facts['validTo_time_t']), self::hasRsaKey($pem));
    }

    public static function fingerprintOf(string $pem): ?string
    {
        if ($pem === '') {
            return null;
        }

        $fingerprint = openssl_x509_fingerprint($pem, 'sha256');

        return $fingerprint === false ? null : $fingerprint;
    }

    /**
     * @param  list<string>  $names
     */
    public function isCurrentFor(array $names): bool
    {
        return array_diff($names, $this->names) === [] && $this->expiresAt->isAfter(now()->addDays(self::RENEW_DAYS));
    }

    /**
     * @param  list<string>  $names
     */
    public function isCurrentForOnly(array $names): bool
    {
        return array_diff($this->names, $names) === [] && $this->isCurrentFor($names);
    }

    private static function hasRsaKey(string $pem): bool
    {
        $key = openssl_pkey_get_public($pem);
        $details = $key === false ? false : openssl_pkey_get_details($key);

        return $details !== false && $details['type'] === OPENSSL_KEYTYPE_RSA;
    }

    /**
     * @return list<string>
     */
    private static function namesIn(string $alternatives): array
    {
        return array_values(array_filter(
            array_map(fn (string $entry): string => trim(str_replace(self::NAME_PREFIXES, '', $entry)), explode(',', $alternatives)),
            fn (string $name): bool => $name !== '',
        ));
    }
}
