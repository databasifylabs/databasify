<?php

declare(strict_types=1);

namespace App\Support\Tls;

use App\DTOs\Databases\KeyPair;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use OpenSSLCertificateSigningRequest;
use RuntimeException;

final readonly class OwnCertificateAuthority
{
    private const int AUTHORITY_DAYS = 3650;

    private const int CERTIFICATE_DAYS = 825;

    public function newAuthority(string $name): KeyPair
    {
        return $this->withConfig('', fn (string $config): KeyPair => $this->signed($name, null, self::AUTHORITY_DAYS, 'authority', $config));
    }

    /**
     * @param  list<string>  $names
     */
    public function issue(KeyPair $authority, string $commonName, array $names): KeyPair
    {
        return $this->withConfig(
            $this->alternativeNamesOf($names),
            fn (string $config): KeyPair => $this->signed($commonName, $authority, self::CERTIFICATE_DAYS, 'server', $config),
        );
    }

    /**
     * @param  callable(string): KeyPair  $issue
     */
    private function withConfig(string $alternativeNames, callable $issue): KeyPair
    {
        $config = tempnam(sys_get_temp_dir(), 'databasify-tls-');

        if ($config === false) {
            throw new RuntimeException('OpenSSL config could not be created.');
        }

        file_put_contents($config, implode("\n", [
            '[req]', 'distinguished_name = subject', '[subject]',
            '[authority]', 'basicConstraints = critical,CA:TRUE', 'keyUsage = critical,keyCertSign,cRLSign',
            '[server]', 'basicConstraints = CA:FALSE', 'keyUsage = digitalSignature', 'extendedKeyUsage = serverAuth,clientAuth', $alternativeNames,
        ])."\n");

        try {
            return $issue($config);
        } finally {
            unlink($config);
        }
    }

    private function signed(string $commonName, ?KeyPair $authority, int $days, string $extensions, string $config): KeyPair
    {
        $key = $this->newKey();
        $certificate = openssl_csr_sign(
            $this->requestFor($commonName, $key, $config),
            $authority?->certificate,
            $authority === null ? $key : $authority->key,
            $days,
            ['digest_alg' => 'sha256', 'config' => $config, 'x509_extensions' => $extensions],
            random_int(1, PHP_INT_MAX),
        );

        return $this->pairOf($certificate, $key, $config);
    }

    private function pairOf(OpenSSLCertificate|false $certificate, OpenSSLAsymmetricKey $key, string $config): KeyPair
    {
        if ($certificate === false) {
            throw new RuntimeException('OpenSSL could not sign the certificate.');
        }

        return new KeyPair($this->pemOfCertificate($certificate), $this->pemOfKey($key, $config));
    }

    private function pemOfCertificate(OpenSSLCertificate $certificate): string
    {
        if (! openssl_x509_export($certificate, $pem) || ! is_string($pem)) {
            throw new RuntimeException('OpenSSL could not export the certificate.');
        }

        return $pem;
    }

    private function pemOfKey(OpenSSLAsymmetricKey $key, string $config): string
    {
        if (! openssl_pkey_export($key, $pem, null, ['config' => $config]) || ! is_string($pem)) {
            throw new RuntimeException('OpenSSL could not export the key.');
        }

        return $pem;
    }

    /**
     * @param  list<string>  $names
     */
    private function alternativeNamesOf(array $names): string
    {
        return 'subjectAltName = '.implode(',', array_map($this->alternativeNameOf(...), $names));
    }

    private function alternativeNameOf(string $name): string
    {
        $type = filter_var($name, FILTER_VALIDATE_IP) === false ? 'DNS' : 'IP';

        return "{$type}:{$name}";
    }

    private function newKey(): OpenSSLAsymmetricKey
    {
        return openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']) ?: throw new RuntimeException('OpenSSL could not create a key.');
    }

    private function requestFor(string $commonName, OpenSSLAsymmetricKey $key, string $config): OpenSSLCertificateSigningRequest
    {
        $request = openssl_csr_new(['commonName' => $commonName], $key, ['digest_alg' => 'sha256', 'config' => $config]);

        return $request instanceof OpenSSLCertificateSigningRequest ? $request : throw new RuntimeException('OpenSSL could not create a certificate request.');
    }
}
