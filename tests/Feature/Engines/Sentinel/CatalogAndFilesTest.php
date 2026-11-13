<?php

declare(strict_types=1);

use App\Engines\Data\EngineField;
use App\Engines\Data\Member;
use App\Engines\Data\MigrationSource;
use App\Engines\Data\MigrationStart;
use App\Engines\Drivers\Sentinel\SentinelFamilyEngine;
use App\Engines\Drivers\Sentinel\SentinelFiles;
use App\Engines\Drivers\Sentinel\SentinelSettings;
use App\Engines\Drivers\Support\NodeState;

it('offers exactly the fields the Go engine offers on each flavour and line', function (string $flavor, string $line) {
    $fields = array_map(fn (EngineField $field): array => $field->toArray(), SentinelSettings::catalogFor(SentinelFamilyEngine::of($flavor), $line)->fields);

    expect($fields)->toEqual(goFixture('catalogs.json', 'sentinel')["{$flavor}-{$line}"]);
})->with(['valkey', 'redis'])->with(['7', '7.2', '8', '9']);

it('tunes like the Go engine', function () {
    foreach (['small' => [512, 1], 'medium' => [4096, 2], 'large' => [65536, 64], 'odd' => [7680, 3]] as $name => [$memory, $cpus]) {
        $tuned = SentinelSettings::catalogFor(SentinelFamilyEngine::of('valkey'), '9')->tuned(new Member('10.0.0.1', 1, true, $cpus, $memory, 10));
        ksort($tuned);
        $expected = goFixture('tuning.json', 'sentinel')[$name];
        ksort($expected);

        expect($tuned)->toBe($expected, $name);
    }
});

it('renders the server conf, sentinel conf and manifest byte for byte like Go', function (string $case) {
    $fixture = goFixture("configure-{$case}.json", 'sentinel');
    $engine = SentinelFamilyEngine::of($fixture['flavor']);
    $request = nodeConfigFromFixture($fixture['request']);
    $catalog = SentinelSettings::catalogFor($engine, '9');
    $effective = NodeState::withSettings($request, $catalog->effective($catalog->tuned(NodeState::selfOf($request)), $request->settings));

    expect(SentinelFiles::serverConfOf($engine, $effective, $fixture['primary']))->toBe($fixture['serverConf'])
        ->and(SentinelFiles::sentinelConfOf($engine, $request, $fixture['primary']))->toBe($fixture['sentinelConf'])
        ->and(SentinelFiles::manifestOf($engine, $request))->toBe($fixture['manifest']);
})->with(['valkey-standalone', 'redis-primary', 'valkey-replica', 'redis-pair']);

it('writes the redis-shake config like Go', function () {
    $fixture = goFixture('shake.json', 'sentinel');

    expect(SentinelFiles::shakeConfigOf(new MigrationStart(new MigrationSource('10.0.0.9', 6380, 'default', 'p"w', true), [], false, null), '/srv/valkey/migration', "local\n"))->toBe($fixture['scan'])
        ->and(SentinelFiles::shakeConfigOf(new MigrationStart(new MigrationSource('::1', 6379, 'app', 'x', false), [], true, null), '/srv/redis/migration', 'pw'))->toBe($fixture['sync']);
});
