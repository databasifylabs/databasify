<?php

declare(strict_types=1);

namespace App\Services\Ssh;

use App\Models\SshKey;
use phpseclib4\Crypt\EC;

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

        return SshKey::query()->create([
            'name' => self::NAME,
            'public_key' => $private->getPublicKey()->toString('OpenSSH', ['comment' => self::NAME]),
            'private_key' => $private->toString('OpenSSH'),
        ]);
    }
}
