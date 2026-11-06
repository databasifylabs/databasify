<?php

declare(strict_types=1);

namespace App\Services\Ssh;

use App\Models\SshKey;
use phpseclib3\Crypt\Common\PublicKey;
use phpseclib3\Crypt\EC;
use RuntimeException;

final readonly class AppSshKey
{
    private const string NAME = 'databasify';

    public function get(): SshKey
    {
        return SshKey::query()->where('name', self::NAME)->first() ?? $this->generate();
    }

    private function generate(): SshKey
    {
        $private = EC::createKey('Ed25519');
        $public = $private->getPublicKey();

        throw_unless($public instanceof PublicKey, RuntimeException::class, 'Could not derive the app public key.');

        return SshKey::query()->create([
            'name' => self::NAME,
            'public_key' => $public->toString('OpenSSH', ['comment' => self::NAME]),
            'private_key' => $private->toString('OpenSSH'),
        ]);
    }
}
