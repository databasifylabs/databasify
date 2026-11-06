<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\Acme\CertificateAuthority;
use App\DTOs\Acme\AcmeCertificate;
use App\DTOs\Acme\DnsChallenge;
use Closure;
use RuntimeException;

final class FakeCertificateAuthority implements CertificateAuthority
{
    /** @var list<string> */
    public array $issued = [];

    private ?string $failure = null;

    public function failWith(string $message): self
    {
        $this->failure = $message;

        return $this;
    }

    public function issue(string $name, Closure $publish): AcmeCertificate
    {
        $publish(new DnsChallenge('_acme-challenge.'.substr($name, 2), 'challenge-token'));

        if ($this->failure !== null) {
            throw new RuntimeException($this->failure);
        }

        $this->issued[] = $name;

        return new AcmeCertificate(issuedPem([$name]), 'zone-key');
    }
}
