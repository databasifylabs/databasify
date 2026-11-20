<?php

declare(strict_types=1);

use App\Services\Ssh\AppSshKey;
use phpseclib4\Crypt\PublicKeyLoader;

test('the app ssh key is an ed25519 pair in openssh format', function () {
    $key = app(AppSshKey::class)->get();

    $derived = PublicKeyLoader::loadPrivateKey($key->private_key)->getPublicKey()->toString('OpenSSH', ['comment' => 'databasify']);

    expect($key->public_key)->toStartWith('ssh-ed25519 ')->toEndWith(' databasify')
        ->and($derived)->toBe($key->public_key);
});

test('the app ssh key is generated once', function () {
    expect(app(AppSshKey::class)->get()->id)->toBe(app(AppSshKey::class)->get()->id);
});
