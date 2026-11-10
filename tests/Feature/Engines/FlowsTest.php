<?php

declare(strict_types=1);

use App\Actions\Databases\Flows\StartFlow;
use App\Actions\Servers\ReleaseAbandonedWork;
use App\Engines\Flows\FlowContext;
use App\Engines\Flows\StartedOperation;
use App\Engines\Flows\Step;
use App\Enums\Databases\FlowState;
use App\Exceptions\Engines\StepNotReady;
use App\Jobs\Databases\AdvanceDatabaseFlow;
use App\Models\Database;
use App\Models\DatabaseFlow;
use Illuminate\Validation\ValidationException;
use Tests\Support\ScriptedFlow;

function operationReply(string $state, string $phase = 'starting', ?array $progress = null): array
{
    return ['key' => 'k', 'kind' => 'node ready', 'state' => $state, 'phase' => $phase, 'progress' => $progress, 'startedAt' => '2026-11-10T01:54:52Z', 'finishedAt' => null, 'error' => $state === 'failed' ? ['code' => 'internal', 'message' => 'the copy broke', 'details' => (object) []] : null];
}

function startScripted(Database $database, ScriptedFlow $flow): DatabaseFlow
{
    return resolve(StartFlow::class)->handle($database, $flow, new FlowContext($database->nodes->pluck('id')->all()));
}

function longStep(array &$keys, string $kind = 'prepare'): Step
{
    return Step::{$kind}('long step', function (DatabaseFlow $flow) use (&$keys): StartedOperation {
        $keys[] = $key = $flow->newOperationKey();

        return new StartedOperation($flow->database->nodes->firstOrFail()->id, $key);
    });
}

beforeEach(function () {
    fakeSsh();
    $this->database = idleDatabase('postgres', 1);
});

it('runs quick steps straight through and finishes', function () {
    $ran = [];
    $flow = ScriptedFlow::bind([ScriptedFlow::quick(function () use (&$ran) {
        $ran[] = 'a';
    }), ScriptedFlow::quick(function () use (&$ran) {
        $ran[] = 'b';
    })]);

    $record = startScripted($this->database, $flow);

    expect($ran)->toBe(['a', 'b'])
        ->and($record->fresh())->state->toBe(FlowState::Succeeded)->step->toBe(2)
        ->and($this->database->fresh()->operation)->toBeNull();
});

it('starts a long step, stores the key and stops', function () {
    $keys = [];
    $ran = [];
    $flow = ScriptedFlow::bind([longStep($keys), ScriptedFlow::quick(function () use (&$ran) {
        $ran[] = 'after';
    })]);

    $record = startScripted($this->database, $flow)->fresh();

    expect($ran)->toBe([])
        ->and($record)->state->toBe(FlowState::Running)->step->toBe(0)->operation_key->toBe($keys[0])->operation_node_id->toBe($this->database->nodes->first()->id)
        ->and($this->database->fresh()->operation)->toBe('Scripted flow');
});

it('moves on when the operation succeeded', function () {
    $keys = [];
    $ran = [];
    $record = startScripted($this->database, ScriptedFlow::bind([longStep($keys), ScriptedFlow::quick(function () use (&$ran) {
        $ran[] = 'after';
    })]));

    fakeSsh()->respondToEngine('operation status', operationReply('succeeded'));
    runJob(new AdvanceDatabaseFlow($record));

    expect($ran)->toBe(['after'])
        ->and($record->fresh())->state->toBe(FlowState::Succeeded)->operation_key->toBeNull()
        ->and(fakeSsh()->runs)->toBe([]);
});

it('starts a lost operation again with a new key', function (array $reply, int $exit) {
    $keys = [];
    $flow = ScriptedFlow::bind([longStep($keys)]);
    $record = startScripted($this->database, $flow);

    fakeSsh()->respondToEngine('operation status', $reply, $exit);
    runJob(new AdvanceDatabaseFlow($record));

    expect($keys)->toHaveCount(2)
        ->and($keys[1])->not->toBe($keys[0])
        ->and($record->fresh())->state->toBe(FlowState::Running)->operation_key->toBe($keys[1])
        ->and($flow->cleanups)->toBe(0);
})->with([
    'not found' => [['error' => ['code' => 'not_found', 'message' => 'no such operation', 'details' => []]], 1],
    'interrupted' => [operationReply('interrupted'), 0],
]);

it('fails the flow and runs cleanup when a prepare or catch-up step fails', function (string $kind) {
    $keys = [];
    $flow = ScriptedFlow::bind([longStep($keys, $kind)]);
    $record = startScripted($this->database, $flow);

    fakeSsh()->respondToEngine('operation status', operationReply('failed'));
    runJob(new AdvanceDatabaseFlow($record));

    expect($record->fresh())->state->toBe(FlowState::Failed)->error->toBe('the copy broke')
        ->and($flow->cleanups)->toBe(1)
        ->and($this->database->fresh()->operation)->toBeNull();
})->with(['prepare', 'catchUp']);

it('fails a quick prepare step and runs cleanup', function () {
    $flow = ScriptedFlow::bind([ScriptedFlow::quick(fn () => throw new RuntimeException('boom'))]);

    $record = startScripted($this->database, $flow);

    expect($record->fresh())->state->toBe(FlowState::Failed)->error->toBe('boom')
        ->and($flow->cleanups)->toBe(1)
        ->and($this->database->fresh()->operation)->toBeNull();
});

it('retries a failed finish step on the next tick', function () {
    $attempts = 0;
    $flow = ScriptedFlow::bind([Step::finish('finish step', function () use (&$attempts): null {
        if (++$attempts === 1) {
            throw new RuntimeException('peer not ready');
        }

        return null;
    })]);

    $record = startScripted($this->database, $flow);

    expect($record->fresh())->state->toBe(FlowState::Running)->error->toBe('peer not ready')
        ->and($flow->cleanups)->toBe(0);

    runJob(new AdvanceDatabaseFlow($record));

    expect($attempts)->toBe(2)
        ->and($record->fresh())->state->toBe(FlowState::Succeeded)->error->toBeNull();
});

it('does not start a step twice when the job runs again while waiting', function () {
    $keys = [];
    $record = startScripted($this->database, ScriptedFlow::bind([longStep($keys)]));

    fakeSsh()->respondToEngine('operation status', operationReply('running'));
    runJob(new AdvanceDatabaseFlow($record));
    runJob(new AdvanceDatabaseFlow($record));

    expect($keys)->toHaveCount(1)
        ->and($record->fresh()->operation_key)->toBe($keys[0]);
});

it('refuses a second flow while one runs', function () {
    $keys = [];
    startScripted($this->database, ScriptedFlow::bind([longStep($keys)]));

    expect(fn () => startScripted($this->database->fresh(), ScriptedFlow::bind([longStep($keys)])))->toThrow(ValidationException::class)
        ->and(DatabaseFlow::query()->where('flow', ScriptedFlow::class)->count())->toBe(1)
        ->and($keys)->toHaveCount(1);
});

it('copies the operation phase and progress onto the flow and the operation title', function () {
    $keys = [];
    $record = startScripted($this->database, ScriptedFlow::bind([longStep($keys)]));

    fakeSsh()->respondToEngine('operation status', operationReply('running', 'copying data', ['done' => 41, 'total' => 120, 'unit' => 'GB']));
    runJob(new AdvanceDatabaseFlow($record));

    expect($record->fresh())->phase->toBe('copying data')
        ->and($record->fresh()->progress)->done->toBe(41)->total->toBe(120)->unit->toBe('GB')
        ->and($this->database->fresh()->operation)->toBe('Scripted flow: copying data, 41 of 120 GB');
});

it('keeps the lock of a database whose flow still runs past the abandoned-work cutoff', function () {
    $keys = [];
    startScripted($this->database, ScriptedFlow::bind([longStep($keys)]));

    $this->travel(9)->hours();
    resolve(ReleaseAbandonedWork::class)->handle();

    expect($this->database->fresh()->operation)->toBe('Scripted flow');
});

it('cancels the operation and fails the flow when the owner stops it', function () {
    $keys = [];
    $flow = ScriptedFlow::bind([longStep($keys)]);
    $record = startScripted($this->database, $flow);
    $ssh = fakeSsh()->respondToEngine('operation cancel', []);
    $this->database->requestStop();

    runJob(new AdvanceDatabaseFlow($record));

    expect($record->fresh())->state->toBe(FlowState::Failed)->error->toBe('Stopped by you.')
        ->and($flow->cleanups)->toBe(1)
        ->and($ssh->scriptAt(0))->toContain('| databasify operation cancel ')
        ->and(json_decode($ssh->engineRequestAt(0), true))->toBe(['key' => $keys[0]]);
});

it('finishes a finish step even when the owner asks to stop', function () {
    $ran = 0;
    $flow = ScriptedFlow::bind([Step::finish('finish step', function () use (&$ran): null {
        $ran++;

        return null;
    })]);
    $this->database->startOperation('Scripted flow');
    $record = $this->database->flows()->create(['flow' => ScriptedFlow::class, 'context' => new FlowContext([]), 'state' => FlowState::Running]);
    $this->database->requestStop();

    runJob(new AdvanceDatabaseFlow($record));

    expect($ran)->toBe(1)
        ->and($record->fresh()->state)->toBe(FlowState::Succeeded)
        ->and($flow->cleanups)->toBe(0);
});

it('waits on a step that is not ready yet without failing it', function () {
    $ready = false;
    $flow = ScriptedFlow::bind([Step::prepare('wait', function () use (&$ready): null {
        throw_unless($ready, StepNotReady::class);

        return null;
    })]);

    $record = startScripted($this->database, $flow);

    expect($record->fresh())->state->toBe(FlowState::Running)->error->toBeNull();

    $ready = true;
    runJob(new AdvanceDatabaseFlow($record));

    expect($record->fresh()->state)->toBe(FlowState::Succeeded);
});
