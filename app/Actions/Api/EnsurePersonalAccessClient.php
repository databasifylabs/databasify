<?php

declare(strict_types=1);

namespace App\Actions\Api;

use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

final readonly class EnsurePersonalAccessClient
{
    private const string NAME = 'Databasify';

    public function __construct(private ClientRepository $clients) {}

    public function handle(): void
    {
        $exists = Passport::client()->newQuery()->where('revoked', false)->get()
            ->contains(fn (Client $client): bool => $client->hasGrantType('personal_access'));

        if ($exists) {
            return;
        }

        $this->clients->createPersonalAccessGrantClient(self::NAME, config()->string('auth.guards.api.provider'));
    }
}
