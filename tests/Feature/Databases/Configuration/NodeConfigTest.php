<?php

declare(strict_types=1);

use App\Actions\Databases\Configuration\UpdateDatabaseConfig;
use App\Actions\Databases\Configuration\UpdateNodeConfig;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\NodeRole;
use App\Jobs\Databases\ApplyNodeSettingsJob;
use App\Models\DatabaseNode;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

it('applies a read-only node config to that node only', function () {
    fakeEngine();
    $database = idleDatabase('postgres', 2);
    $reader = withReadOnlyNode($database);
    Queue::fake([ApplyNodeSettingsJob::class]);

    resolve(UpdateNodeConfig::class)->handle($reader, ['work_mem' => '32MB']);
    runJob(new ApplyNodeSettingsJob($reader->fresh()));

    $members = $database->nodes()->members()->with('server')->get();
    expect(recordedCalls($reader->server))->toContain('config.apply')
        ->and($members->flatMap(fn (DatabaseNode $node): array => recordedCalls($node->server))->all())->not->toContain('config.apply')
        ->and($reader->fresh()?->config)->toBe(['work_mem' => '32MB'])
        ->and($reader->fresh()?->status)->toBe(DatabaseStatus::Ready)
        ->and($database->fresh()?->status)->toBe(DatabaseStatus::Ready);
});

it('refuses a setting the primary controls for every node on a read-only node', function () {
    $database = idleDatabase('postgres', 1);
    $reader = withReadOnlyNode($database);

    expect(fn () => resolve(UpdateNodeConfig::class)->handle($reader, ['max_connections' => '300']))
        ->toThrow(ValidationException::class, 'max_connections is set on the primary for every node, change it on main.');
});

it('leaves read-only nodes out of a main config change', function () {
    fakeEngine();
    $database = idleDatabase('postgres', 2);
    markRoles($database, NodeRole::Primary, NodeRole::Replica);
    $reader = withReadOnlyNode($database);

    resolve(UpdateDatabaseConfig::class)->handle($database, ['work_mem' => '16MB']);

    expect(recordedCalls($reader->server))->not->toContain('nodes.configure')->not->toContain('config.apply')
        ->and($reader->fresh()?->config)->toBe([]);
});

it('restarts read-only nodes after the main changes a setting the primary controls', function () {
    fakeEngine();
    answerRead('config.pendingRestart', ['max_connections']);
    $database = idleDatabase('postgres', 2);
    markRoles($database, NodeRole::Primary, NodeRole::Replica);
    $reader = withReadOnlyNode($database);

    resolve(UpdateDatabaseConfig::class)->handle($database, ['max_connections' => '300']);

    expect(recordedCalls($reader->server))->toContain('nodes.restart')
        ->and($reader->fresh()?->config)->toBe([])
        ->and($reader->fresh()?->status)->toBe(DatabaseStatus::Ready);
});

it('leaves a read-only node busy with its own operation to restart later', function () {
    fakeEngine();
    answerRead('config.pendingRestart', ['max_connections']);
    $database = idleDatabase('postgres', 2);
    markRoles($database, NodeRole::Primary, NodeRole::Replica);
    $reader = withReadOnlyNode($database);
    $reader->markBusy(DatabaseStatus::Rebooting);

    resolve(UpdateDatabaseConfig::class)->handle($database, ['max_connections' => '300']);

    expect(recordedCalls($reader->server))->not->toContain('nodes.restart')
        ->and($reader->fresh()?->status)->toBe(DatabaseStatus::Rebooting)
        ->and($database->fresh()?->status)->toBe(DatabaseStatus::Ready);
});
