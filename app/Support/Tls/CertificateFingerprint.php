<?php

declare(strict_types=1);

namespace App\Support\Tls;

final readonly class CertificateFingerprint
{
    public static function of(string $pem): ?string
    {
        if ($pem === '') {
            return null;
        }

        $fingerprint = openssl_x509_fingerprint($pem, 'sha256');

        return $fingerprint === false ? null : $fingerprint;
    }
}
