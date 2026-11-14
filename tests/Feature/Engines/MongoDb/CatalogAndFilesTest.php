<?php

declare(strict_types=1);

use App\Engines\Data\EngineField;
use App\Engines\Data\Member;
use App\Engines\Drivers\MongoDb\MongoDbFiles;
use App\Engines\Drivers\MongoDb\MongoDbSettings;
use App\Engines\Drivers\Support\NodeState;

it('offers exactly the fields the Go engine offers', function () {
    expect(array_map(fn (EngineField $field): array => $field->toArray(), MongoDbSettings::catalog()->fields))->toEqual(goFixture('catalog.json', 'mongodb'));
});

it('tunes the same values as the Go engine for each server size', function () {
    foreach (['small' => [512, 1], 'medium' => [4096, 2], 'large' => [65536, 32], 'odd' => [7680, 3]] as $name => [$memory, $cpus]) {
        $tuned = MongoDbSettings::catalog()->tuned(new Member('10.0.0.1', 1, true, $cpus, $memory, 10));
        ksort($tuned);
        $expected = goFixture('tuning.json', 'mongodb')[$name];
        ksort($expected);

        expect($tuned)->toBe($expected, $name);
    }
});

it('renders mongod.conf, the keyfile and the manifest byte for byte like the Go engine', function (string $case) {
    $fixture = goFixture("configure-{$case}.json", 'mongodb');
    $request = nodeConfigFromFixture($fixture['request']);
    $catalog = MongoDbSettings::catalog();
    $effective = $catalog->effective($catalog->tuned(NodeState::selfOf($request)), $request->settings);

    expect(MongoDbFiles::mongodConfOf($effective))->toBe($fixture['mongodConf'])
        ->and(MongoDbFiles::keyfileOf((string) $request->secrets->replication))->toBe($fixture['keyfile'])
        ->and(MongoDbFiles::manifestOf($request))->toBe($fixture['manifest']);
})->with(['standalone', 'archived', 'readonly']);

it('renders the PBM config and URI like the Go engine', function () {
    $fixture = goFixture('pbm-config.json', 'mongodb');
    $storage = nodeConfigFromFixture(goFixture('configure-archived.json', 'mongodb')['request'])->archive->storage;

    expect(MongoDbFiles::pbmConfigOf($storage, true))->toBe($fixture['sliced'])
        ->and(MongoDbFiles::pbmConfigOf($storage, false))->toBe($fixture['plain'])
        ->and(MongoDbFiles::pbmUriOf('ag$ent/x@y'))->toBe($fixture['uri']);
});

it('escapes the PBM password like Go url.UserPassword', function () {
    expect(MongoDbFiles::pbmUriOf("a!b'c(d)e*f~g\$h&i+j,k;l=m"))->toStartWith('mongodb://databasify_pbm:a%21b%27c%28d%29e%2Af~g$h&i+j,k;l=m@');
});
