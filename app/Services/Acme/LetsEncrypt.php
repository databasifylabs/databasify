<?php

declare(strict_types=1);

namespace App\Services\Acme;

use Afosto\Acme\Client;
use Afosto\Acme\Data\Authorization;
use Afosto\Acme\Data\Challenge;
use Afosto\Acme\Data\Record;
use App\Contracts\Acme\CertificateAuthority;
use App\DTOs\Acme\AcmeCertificate;
use App\DTOs\Acme\DnsChallenge;
use Closure;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;

final readonly class LetsEncrypt implements CertificateAuthority
{
    private const int VALIDATION_ATTEMPTS = 30;

    public function issue(string $name, Closure $publish): AcmeCertificate
    {
        $client = $this->client();
        $order = $client->createOrder([$name]);
        $authorizations = $client->authorize($order);

        collect($authorizations)->each(fn (Authorization $authorization) => $publish($this->challengeOf($authorization)));
        Sleep::for(config()->integer('databasify.acme.propagation_seconds'))->seconds();
        collect($authorizations)->each(fn (Authorization $authorization) => $this->validate($client, $authorization));

        $certificate = $client->getCertificate($order);

        return new AcmeCertificate($certificate->getCertificate(), $certificate->getPrivateKey());
    }

    private function client(): Client
    {
        return new AcmeClient([
            'username' => 'databasify',
            'fs' => new Filesystem(new LocalFilesystemAdapter(Storage::disk('local')->path(''))),
            'basePath' => 'acme',
            'mode' => config()->string('databasify.acme.mode'),
            'key_length' => 2048,
        ]);
    }

    private function challengeOf(Authorization $authorization): DnsChallenge
    {
        $record = $authorization->getTxtRecord();

        if (! $record instanceof Record) {
            throw new RuntimeException("Let's Encrypt offered no DNS challenge for {$authorization->getDomain()}.");
        }

        return new DnsChallenge($record->getName(), $record->getValue());
    }

    private function validate(Client $client, Authorization $authorization): void
    {
        $challenge = $authorization->getDnsChallenge();

        if (! $challenge instanceof Challenge || ! $client->validate($challenge, self::VALIDATION_ATTEMPTS)) {
            throw new RuntimeException("Let's Encrypt could not validate {$authorization->getDomain()}.");
        }
    }
}
