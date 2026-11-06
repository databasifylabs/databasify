<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseStatus;

it('keeps settings saved by a newer copy when a stale copy only changes the status', function () {
    $database = idleDatabase('valkey', 1);
    $stale = $database->fresh();
    $stale->settings;

    $database->update(['settings' => $database->settings->withConfig(['maxmemory-policy' => 'allkeys-lru'])]);
    $stale->update(['status' => DatabaseStatus::Degraded]);

    expect($stale->isDirty('settings'))->toBeFalse()
        ->and($database->fresh()->settings->config)->toMatchArray(['maxmemory-policy' => 'allkeys-lru'])
        ->and($database->fresh()->status)->toBe(DatabaseStatus::Degraded);
});

it('still saves settings that really changed', function () {
    $database = idleDatabase('valkey', 1);

    $database->update(['settings' => $database->settings->withConfig(['timeout' => '120'])]);

    expect($database->fresh()->settings->config)->toMatchArray(['timeout' => '120']);
});
