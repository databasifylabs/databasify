<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

function replsetProbe(string $assertions): array
{
    $source = "const env = {};\nfunction failure(code, message) { const error = new Error(message); error.databasify = code; return error; }\n"
        .view('engines.mongodb._replset')->render()."\n".$assertions;
    $run = Process::input($source)->run(['node', '-']);

    expect($run->successful())->toBeTrue($run->errorOutput());

    return json_decode(trim($run->output()), true);
}

it('adds a member without a vote and gives it one only as a secondary, up to seven voters', function () {
    $result = replsetProbe(<<<'JS'
        const config = { _id: 'databasify', version: 3, members: [{ _id: 0, host: 'a:25060' }, { _id: 4, host: 'b:25060', votes: 1, priority: 1 }] };
        const [added, changed] = withMember(config, 'c:25060');
        const [again, unchanged] = withMember(added, 'c:25060');
        const [voted, gotVote] = withVote(added, 'c:25060', true);
        const [notSecondary, refused] = withVote(added, 'c:25060', false);
        const seven = { members: Array.from({ length: 7 }, (_, i) => ({ _id: i, host: 'h' + i })).concat([{ _id: 7, host: 'x', votes: 0 }]) };
        console.log(JSON.stringify({ added: added.members[2], version: added.version, changed, unchanged, voted: voted.members[2], gotVote, refused, full: withVote(seven, 'x', true)[1], voters: votersOf(added) }));
        JS);

    expect($result)->toBe([
        'added' => ['_id' => 5, 'host' => 'c:25060', 'votes' => 0, 'priority' => 0],
        'version' => 4,
        'changed' => true,
        'unchanged' => false,
        'voted' => ['_id' => 5, 'host' => 'c:25060', 'votes' => 1, 'priority' => 1],
        'gotVote' => true,
        'refused' => false,
        'full' => false,
        'voters' => 2,
    ]);
});

it('keeps every other member when it removes a vote, a host or sets a priority', function () {
    $result = replsetProbe(<<<'JS'
        const config = { version: 1, members: [{ _id: 0, host: 'a', votes: 1, priority: 1, tags: { dc: 'x' } }, { _id: 1, host: 'b', votes: 1, priority: 1 }] };
        console.log(JSON.stringify({
          withoutVote: withoutVote(config, 'b')[0].members,
          already: withoutVote(withoutVote(config, 'b')[0], 'b')[1],
          withoutHost: withoutHost(config, 'b').members,
          priority: withPriority(config, 'b', 2).members,
          alone: votingAlone({ _id: 3, host: 'z', votes: 0, priority: 0, hidden: true }),
        }));
        JS);

    expect($result['withoutVote'])->toBe([['_id' => 0, 'host' => 'a', 'votes' => 1, 'priority' => 1, 'tags' => ['dc' => 'x']], ['_id' => 1, 'host' => 'b', 'votes' => 0, 'priority' => 0]])
        ->and($result['already'])->toBeFalse()
        ->and($result['withoutHost'])->toBe([['_id' => 0, 'host' => 'a', 'votes' => 1, 'priority' => 1, 'tags' => ['dc' => 'x']]])
        ->and($result['priority'][1])->toBe(['_id' => 1, 'host' => 'b', 'votes' => 1, 'priority' => 2])
        ->and($result['alone'])->toBe(['_id' => 3, 'host' => 'z', 'votes' => 1, 'priority' => 1]);
});

it('counts healthy voters and spots a member too stale to catch up', function () {
    $result = replsetProbe(<<<'JS'
        const config = { members: [{ host: 'a' }, { host: 'b', votes: 1 }, { host: 'c', votes: 0 }] };
        const status = { members: [{ name: 'a', health: 1, stateStr: 'PRIMARY' }, { name: 'b', health: 0, stateStr: 'SECONDARY' }, { name: 'c', health: 1, stateStr: 'SECONDARY' }] };
        const stale = { members: [{ self: true, stateStr: 'RECOVERING', infoMessage: 'error RS102 too stale to catch up' }] };
        console.log(JSON.stringify({ healthy: healthyVoters(config, status), primary: hasPrimary(status), stale: tooStale(stale), fresh: tooStale(status) }));
        JS);

    expect($result)->toBe(['healthy' => 1, 'primary' => true, 'stale' => true, 'fresh' => false]);
});
