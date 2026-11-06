<?php

declare(strict_types=1);

namespace App\Contracts\Acme;

use App\DTOs\Acme\AcmeCertificate;
use App\DTOs\Acme\DnsChallenge;
use App\Services\Acme\LetsEncrypt;
use Closure;
use Illuminate\Container\Attributes\Bind;

#[Bind(LetsEncrypt::class)]
interface CertificateAuthority
{
    /**
     * @param  Closure(DnsChallenge): void  $publish
     */
    public function issue(string $name, Closure $publish): AcmeCertificate;
}
