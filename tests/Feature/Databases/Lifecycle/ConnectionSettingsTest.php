<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseMode;

it('prints laravel settings for both engines', function () {
    $valkey = newDatabase('valkey', 1, DatabaseMode::Single);
    $valkey->update(['settings' => $valkey->settings->withCertificates(['ca.crt' => 'CA'])]);
    $valkey = $valkey->fresh();
    $mysql = newDatabase('mysql', 1, DatabaseMode::Single, 'db');

    expect($valkey->environment($valkey->endpoints()))
        ->toBe(['REDIS_CLIENT' => 'phpredis', 'REDIS_SCHEME' => 'tls', 'REDIS_HOST' => $valkey->endpoints()->public->host, 'REDIS_PORT' => '25061', 'REDIS_PASSWORD' => $valkey->settings->secret('ENGINE_ADMIN')])
        ->and($mysql->environment($mysql->endpoints()))
        ->toMatchArray(['DB_PORT' => '25060', 'DB_DATABASE' => 'defaultdb'])
        ->not->toHaveKey('MYSQL_ATTR_SSL_CA');
});
