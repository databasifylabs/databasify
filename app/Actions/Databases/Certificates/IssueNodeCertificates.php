<?php

declare(strict_types=1);

namespace App\Actions\Databases\Certificates;

use App\DTOs\Databases\IssuedCertificate;
use App\DTOs\Databases\KeyPair;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Support\Tls\OwnCertificateAuthority;

final readonly class IssueNodeCertificates
{
    private const string AUTHORITY_KEY = 'ca.key';

    public const string CERTIFICATE = 'server.crt';

    public const string KEY = 'server.key';

    public function __construct(private OwnCertificateAuthority $authority) {}

    public function handle(Database $database): void
    {
        $authority = $this->authorityOf($database);
        $settings = $database->settings->mergingCertificates([Database::CERTIFICATE_AUTHORITY => $authority->certificate, self::AUTHORITY_KEY => $authority->key]);
        $databaseNames = $database->certificateNames();

        foreach ($database->nodes()->with('server')->get() as $node) {
            $names = $this->namesOf($node, $databaseNames);

            if (IssuedCertificate::fromPem($settings->nodeCertificate($node->id, self::CERTIFICATE) ?? '')?->isCurrentFor($names) === true) {
                continue;
            }

            $issued = $this->authority->issue($authority, $node->displayName(), $names);
            $settings = $settings->withNodeCertificates($node->id, [self::CERTIFICATE => $issued->certificate, self::KEY => $issued->key]);
        }

        $database->update(['settings' => $settings]);
    }

    private function authorityOf(Database $database): KeyPair
    {
        $certificate = $database->settings->certificate(Database::CERTIFICATE_AUTHORITY);
        $key = $database->settings->certificate(self::AUTHORITY_KEY);

        if ($certificate !== '' && $key !== '') {
            return new KeyPair($certificate, $key);
        }

        return $this->authority->newAuthority("{$database->name} CA");
    }

    /**
     * @param  list<string>  $databaseNames
     * @return list<string>
     */
    private function namesOf(DatabaseNode $node, array $databaseNames): array
    {
        return array_values(array_unique([...$databaseNames, ...$node->certificateNames()]));
    }
}
