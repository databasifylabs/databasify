<?php

declare(strict_types=1);

use App\Actions\Providers\RegisterProviderKey;
use App\Exceptions\Providers\ProviderRequestFailed;
use App\Models\ServerProvider;
use App\ServerProviders\Drivers\DigitalOcean\DigitalOcean;
use App\Support\Ssh\Fingerprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const RACE_PUBLIC_KEY = 'ssh-ed25519 cmFjZS1rZXk= databasify';

function registerRaceKey(): string
{
    return resolve(RegisterProviderKey::class)->handle(new DigitalOcean(new ServerProvider(['credentials' => ['token' => 't']])), RACE_PUBLIC_KEY);
}

function raceKeyUrl(): string
{
    return 'https://api.digitalocean.com/v2/account/keys/'.Fingerprint::md5(RACE_PUBLIC_KEY);
}

it('reuses the key the account already has without adding it', function () {
    Http::fake([raceKeyUrl() => Http::response(['ssh_key' => ['id' => 77]])]);

    expect(registerRaceKey())->toBe('77');
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});

it('looks the key up again when a parallel order added it first', function () {
    Http::fake([
        raceKeyUrl() => Http::sequence()->push(['id' => 'not_found'], 404)->push(['ssh_key' => ['id' => 77]]),
        'https://api.digitalocean.com/v2/account/keys' => Http::response(['id' => 'unprocessable_entity', 'message' => 'SSH Key is already in use on your account'], 422),
    ]);

    expect(registerRaceKey())->toBe('77');
    Http::assertSentCount(3);
});

it('reports the refusal when the key is still missing after adding it fails', function () {
    Http::fake([
        raceKeyUrl() => Http::response(['id' => 'not_found'], 404),
        'https://api.digitalocean.com/v2/account/keys' => Http::response(['message' => 'Key invalid'], 422),
    ]);

    expect(registerRaceKey(...))->toThrow(ProviderRequestFailed::class, 'DigitalOcean answered 422. Key invalid');
});
