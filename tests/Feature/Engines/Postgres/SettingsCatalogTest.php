<?php

declare(strict_types=1);

use App\Engines\Data\EngineField;
use App\Engines\Data\Member;
use App\Engines\Drivers\Postgres\PostgresSettings;
use App\Exceptions\Engines\InvalidEngineRequest;

it('offers exactly the fields the Go engine offers on each line', function (string $line) {
    $fields = array_map(fn (EngineField $field): array => $field->toArray(), PostgresSettings::catalogFor($line)->fields);

    expect($fields)->toEqual(goFixture("catalog-{$line}.json"));
})->with(['17', '18']);

it('tunes the same values as the Go engine for each server size', function () {
    $sizes = ['small' => [512, 1, 10], 'medium' => [4096, 2, 80], 'large' => [65536, 32, 2000]];

    foreach ($sizes as $name => [$memory, $cpus, $disk]) {
        $tuned = PostgresSettings::catalogFor('18')->tuned(new Member('10.0.0.1', 1, true, $cpus, $memory, $disk));
        ksort($tuned);
        $expected = goFixture('tuning.json')[$name];
        ksort($expected);

        expect($tuned)->toBe($expected);
    }
});

it('refuses settings the catalog does not offer or accept, with the Go wording', function (array $settings, string $message) {
    expect(fn () => PostgresSettings::catalogFor('18')->check($settings))->toThrow(InvalidEngineRequest::class, $message);
})->with([
    'unknown' => [['fsync' => 'off'], 'setting "fsync" is not offered; config fields lists the settings'],
    'out of range' => [['max_connections' => '0'], 'setting "max_connections" does not accept "0"'],
    'bad size' => [['work_mem' => '8 MB'], 'setting "work_mem" does not accept "8 MB"'],
    'bad choice' => [['jit' => 'maybe'], 'setting "jit" does not accept "maybe"'],
    'bad pattern' => [['timezone' => 'Asia/Dhaka;'], 'setting "timezone" does not accept "Asia/Dhaka;"'],
]);

it('accepts valid values of every kind', function () {
    PostgresSettings::catalogFor('18')->check(['work_mem' => '8MB', 'random_page_cost' => '1.1', 'jit' => 'off', 'timezone' => 'Asia/Dhaka', 'max_slot_wal_keep_size' => '-1', 'statement_timeout' => '5000']);
})->throwsNoExceptions();

it('lets user settings win over tuned ones and reports where each came from', function () {
    $catalog = PostgresSettings::catalogFor('18');
    $tuned = ['work_mem' => '8MB', 'shared_buffers' => '1024MB'];
    $user = ['work_mem' => '16MB'];

    expect($catalog->effective($tuned, $user))->toBe(['work_mem' => '16MB', 'shared_buffers' => '1024MB'])
        ->and($catalog->sourceOf('work_mem', $tuned, $user))->toBe('user')
        ->and($catalog->sourceOf('shared_buffers', $tuned, $user))->toBe('tuned')
        ->and($catalog->sourceOf('jit', $tuned, $user))->toBe('default')
        ->and($catalog->restartChanged(['shared_buffers' => '512MB'], $tuned, $user))->toBe(['shared_buffers']);
});
