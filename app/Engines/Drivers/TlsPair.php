<?php

declare(strict_types=1);

namespace App\Engines\Drivers;

use App\Engines\Requests\TlsInstall;
use App\Exceptions\Engines\InvalidEngineRequest;

final readonly class TlsPair
{
    public static function check(TlsInstall $request): void
    {
        self::checkPair($request->certificate, $request->key, $request->ca);
    }

    public static function checkPair(string $certificatePem, string $key, string $ca): void
    {
        $certificate = openssl_x509_read($certificatePem);

        if ($certificate === false || ! openssl_x509_check_private_key($certificate, $key)) {
            throw new InvalidEngineRequest('invalid_request', 'the certificate and key are not a matching PEM pair: '.(openssl_error_string() ?: 'tls: private key does not match public key'));
        }

        if (preg_match('/-----BEGIN [A-Z ]+-----/', $ca) !== 1) {
            throw new InvalidEngineRequest('invalid_request', 'the CA is not PEM');
        }
    }
}
