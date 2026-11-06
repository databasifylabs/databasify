<?php

declare(strict_types=1);

it('knows when a database survives a primary crash on its own', function () {
    $mysql = idleDatabase('mysql', 2);
    $valkey = idleDatabase('valkey', 2, name: 'cache');

    expect($mysql->canFailover())->toBeFalse()
        ->and($valkey->canFailover())->toBeFalse();

    withReadOnlyNode($mysql);
    withReadOnlyNode($valkey);

    expect($mysql->canFailover())->toBeFalse()
        ->and($valkey->canFailover())->toBeTrue();
});
